<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchupResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\QueueResource;
use App\Http\Traits\ApiResponse;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;
use App\Services\WaitEstimator;

class PlayerStatusController extends Controller
{
    use ApiResponse;

    public function __construct(private WaitEstimator $estimator) {}

    public function show(string $token)
    {
        $player = Player::where('qr_token', $token)->first();

        if (! $player) {
            return $this->success(null, 'Player not found.', 404);
        }

        $record = $this->record($player);
        $entry = $player->activeQueueEntry();

        if (! $entry) {
            return $this->success(array_merge([
                'player' => new PlayerResource($player),
                'queue' => null,
                'matchup' => null,
            ], $record));
        }

        $position = null;
        if (in_array($entry->status, ['waiting', 'called'], true)) {
            $position = Queue::whereIn('status', ['waiting', 'called'])
                ->where(function ($q) use ($entry) {
                    $q->where('joined_at', '<', $entry->joined_at)
                        ->orWhere(function ($q2) use ($entry) {
                            $q2->where('joined_at', $entry->joined_at)
                                ->where('queue_number', '<', $entry->queue_number);
                        });
                })
                ->count() + 1;
        }
        $entry->setAttribute('position', $position);
        $estimates = null;
        if (in_array($entry->status, ['waiting', 'called'], true)) {
            $estimates = $this->estimator->build();
            $entry->setAttribute('estimated_minutes', $estimates['queue'][$entry->id] ?? null);
        }

        $entry->load('player', 'gamePlayer.game.court', 'gamePlayer.game.gamePlayers.player', 'gamePlayer.game.scores');

        $matchup = null;
        $pendingMatchupId = DB::table('matchup_players')
            ->join('matchups', 'matchups.id', '=', 'matchup_players.matchup_id')
            ->where('matchup_players.queue_id', $entry->id)
            ->where('matchups.status', 'pending')
            ->value('matchup_players.matchup_id');

        if ($pendingMatchupId) {
            $pendingIds = Matchup::pending()->pluck('id')->values();
            $matchupPosition = $pendingIds->search($pendingMatchupId);
            $matchupModel = Matchup::with('matchupPlayers.player')->find($pendingMatchupId);
            $matchup = (new MatchupResource($matchupModel))->resolve();
            $matchup['position'] = $matchupPosition !== false ? $matchupPosition + 1 : null;
            $matchup['estimated_minutes'] = $estimates['matchups'][$pendingMatchupId] ?? null;
        }

        return $this->success(array_merge([
            'player' => new PlayerResource($player),
            'queue' => new QueueResource($entry),
            'matchup' => $matchup,
        ], $record));
    }

    private function record(Player $player): array
    {
        $stats = DB::table('scores')
            ->join('game_players', function ($join) {
                $join->on('game_players.game_id', '=', 'scores.game_id')
                    ->on('game_players.side', '=', 'scores.side');
            })
            ->join('games', 'games.id', '=', 'scores.game_id')
            ->where('game_players.player_id', $player->id)
            ->where('games.status', 'completed')
            ->selectRaw("
                COUNT(*) as games_played,
                COUNT(*) FILTER (WHERE scores.result = 'win') as wins,
                COUNT(*) FILTER (WHERE scores.result = 'loss') as losses,
                COUNT(*) FILTER (WHERE scores.result = 'draw') as draws,
                COALESCE(SUM(scores.points), 0) as total_points,
                AVG(EXTRACT(EPOCH FROM (games.completed_at - games.started_at))) as avg_seconds
            ")
            ->first();

        $recent = DB::table('game_players')
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->join('courts', 'courts.id', '=', 'games.court_id')
            ->join('scores as mine', function ($join) {
                $join->on('mine.game_id', '=', 'game_players.game_id')
                    ->on('mine.side', '=', 'game_players.side');
            })
            ->join('scores as theirs', function ($join) {
                $join->on('theirs.game_id', '=', 'game_players.game_id')
                    ->on('theirs.side', '!=', 'game_players.side');
            })
            ->where('game_players.player_id', $player->id)
            ->where('games.status', 'completed')
            ->orderByDesc('games.completed_at')
            ->limit(5)
            ->select(
                'games.id as game_id',
                'courts.name as court',
                'games.completed_at',
                'mine.points as my_points',
                'theirs.points as their_points',
                'mine.result as result',
                DB::raw('EXTRACT(EPOCH FROM (games.completed_at - games.started_at)) as duration_seconds'),
            )
            ->get();

        return [
            'stats' => [
                'games_played' => (int) $stats->games_played,
                'wins' => (int) $stats->wins,
                'losses' => (int) $stats->losses,
                'draws' => (int) $stats->draws,
                'total_points' => (int) $stats->total_points,
                'avg_seconds' => $stats->avg_seconds !== null ? (int) round($stats->avg_seconds) : null,
            ],
            'recent_games' => $recent->map(fn($g) => [
                'game_id' => (int) $g->game_id,
                'court' => $g->court,
                'completed_at' => $g->completed_at,
                'my_points' => (int) $g->my_points,
                'their_points' => (int) $g->their_points,
                'result' => $g->result,
                'duration_seconds' => $g->duration_seconds !== null ? (int) round($g->duration_seconds) : null,
            ])->all(),
        ];
    }
}
