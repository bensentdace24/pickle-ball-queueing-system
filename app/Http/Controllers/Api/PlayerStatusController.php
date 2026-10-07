<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchupResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\QueueResource;
use App\Http\Traits\ApiResponse;
use App\Models\Player;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class PlayerStatusController extends Controller
{
    use ApiResponse;

    public function show(string $token)
    {
        $player = Player::where('qr_token', $token)->first();

        if (! $player) {
            return $this->success(null, 'Player not found.', 404);
        }

        $entry = $player->activeQueueEntry();

        if (! $entry) {
            return $this->success([
                'player' => new PlayerResource($player),
                'queue' => null,
                'matchup' => null,
            ]);
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

        $entry->load('player', 'gamePlayer.game.court', 'gamePlayer.game.gamePlayers.player', 'gamePlayer.game.scores');

        $matchup = null;
        $pendingMatchupId = DB::table('matchup_players')
            ->join('matchups', 'matchups.id', '=', 'matchup_players.matchup_id')
            ->where('matchup_players.queue_id', $entry->id)
            ->where('matchups.status', 'pending')
            ->value('matchup_players.matchup_id');

        if ($pendingMatchupId) {
            $pendingIds = \App\Models\Matchup::pending()->pluck('id')->values();
            $position = $pendingIds->search($pendingMatchupId);
            $matchupModel = \App\Models\Matchup::with('matchupPlayers.player')->find($pendingMatchupId);
            $matchup = (new MatchupResource($matchupModel))->resolve();
            $matchup['position'] = $position !== false ? $position + 1 : null;
        }

        return $this->success([
            'player' => new PlayerResource($player),
            'queue' => new QueueResource($entry),
            'matchup' => $matchup,
        ]);
    }
}
