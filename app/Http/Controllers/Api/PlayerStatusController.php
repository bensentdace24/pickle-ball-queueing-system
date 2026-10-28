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
use App\Models\Court;
use Illuminate\Support\Facades\DB;
use App\Services\WaitEstimator;
use App\Exceptions\BusinessRuleException;
use App\Services\MatchupService;
use App\Services\QueueService;
use App\Models\RankEvent;
use App\Services\RankService;

class PlayerStatusController extends Controller
{
    use ApiResponse;

    public function __construct(
        private WaitEstimator $estimator,
        private QueueService $queues,
        private MatchupService $matchups,
    ) {}

    public function show(string $token)
    {
        $player = Player::where('qr_token', $token)->first();

        if (! $player) {
            return $this->success(null, 'This link is no longer valid. Please ask the front desk for your QR code.', 404);
        }

        $record = $this->record($player);
        $entry = $player->activeQueueEntry();

        if (! $entry) {
            return $this->success(array_merge([
                'player' => new PlayerResource($player),
                'queue' => null,
                'matchup' => null,
                'active_courts' => $this->activeCourts(),
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
            'active_courts' => $this->activeCourts(),
        ], $record));
    }

    /** Public waiting-room view: expose only display names, never phone or QR data. */
    private function activeCourts(): array
    {
        return Court::where('status', 'playing')
            ->whereHas('activeGame')
            ->with('activeGame.gamePlayers.player')
            ->orderBy('id')
            ->get()
            ->map(function (Court $court) {
                $game = $court->activeGame;
                $players = $game->gamePlayers->map(fn($gamePlayer) => [
                    'id' => $gamePlayer->player->id,
                    'name' => $gamePlayer->player->name,
                    'skill_level' => $gamePlayer->player->skill_level,
                ])->values();
                $team = fn(int $side) => [
                    'players' => $game->gamePlayers
                        ->where('side', $side)
                        ->map(fn($gamePlayer) => [
                            'id' => $gamePlayer->player->id,
                            'name' => $gamePlayer->player->name,
                            'skill_level' => $gamePlayer->player->skill_level,
                        ])->values(),
                    'points' => null,
                    'result' => null,
                ];

                return [
                    'id' => $court->id,
                    'name' => $court->name,
                    'status' => $court->status,
                    'active_game' => [
                        'id' => $game->id,
                        'status' => $game->status,
                        'started_at' => $game->started_at,
                        'completed_at' => null,
                        'duration_minutes' => $game->duration_minutes,
                        'ends_at' => $game->duration_minutes && $game->started_at
                            ? $game->started_at->copy()->addMinutes($game->duration_minutes)
                            : null,
                        'players' => $players,
                        'team_a' => $team(0),
                        'team_b' => $team(1),
                        'skill_warning' => null,
                    ],
                ];
            })
            ->all();
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

        $changes = RankEvent::where('player_id', $player->id)
            ->whereIn('game_id', $recent->pluck('game_id'))
            ->pluck('delta', 'game_id');

        return [
            'stats' => [
                'games_played' => (int) $stats->games_played,
                'wins' => (int) $stats->wins,
                'losses' => (int) $stats->losses,
                'draws' => (int) $stats->draws,
                'total_points' => (int) $stats->total_points,
                'avg_seconds' => $stats->avg_seconds !== null ? (int) round($stats->avg_seconds) : null,
            ],
            'rank' => app(RankService::class)->summary((int) $player->rank_points),
            'recent_games' => $recent->map(fn($g) => [
                'game_id' => (int) $g->game_id,
                'court' => $g->court,
                'completed_at' => $g->completed_at,
                'my_points' => (int) $g->my_points,
                'their_points' => (int) $g->their_points,
                'result' => $g->result,
                'duration_seconds' => $g->duration_seconds !== null ? (int) round($g->duration_seconds) : null,
                'rank_change' => isset($changes[$g->game_id]) ? (int) $changes[$g->game_id] : null,
            ])->all(),
        ];
    }

    public function cancel(string $token)
    {
        $player = Player::where('qr_token', $token)->firstOrFail();

        DB::transaction(function () use ($player) {
            $entry = $player->activeQueueEntry();

            if (! $entry || ! in_array($entry->status, ['pending', 'waiting', 'called'], true)) {
                throw new BusinessRuleException('There is nothing to cancel right now.');
            }

            $matchupId = DB::table('matchup_players')
                ->join('matchups', 'matchups.id', '=', 'matchup_players.matchup_id')
                ->where('matchup_players.queue_id', $entry->id)
                ->where('matchups.status', 'pending')
                ->value('matchup_players.matchup_id');

            if ($matchupId) {
                // Breaks up the Up Next match; everyone in it goes back to Waiting.
                $this->matchups->cancel(Matchup::findOrFail($matchupId));
                $entry->refresh();
            }

            $this->queues->cancel($entry);
        });

        return $this->success(null, 'Your spot was cancelled.');
    }
}
