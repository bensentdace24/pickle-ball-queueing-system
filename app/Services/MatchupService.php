<?php

namespace App\Services;

use App\Enums\QueueStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Court;
use App\Models\Game;
use App\Models\Matchup;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class MatchupService
{
    public const MAX_PENDING = 3;

    public function __construct(private GameService $games) {}

    /**
     * Manually form a matchup from specific queue entries + sides.
     *
     * @param  array<int, array{queue_id:int, side:int}>  $assignments
     */
    public function form(array $assignments, ?int $durationMinutes = null): Matchup
    {
        $size = count($assignments);

        if (! in_array($size, [2, 4], true)) {
            throw new BusinessRuleException('A matchup needs exactly 2 players (singles) or 4 players (doubles).');
        }

        $queueIds = array_column($assignments, 'queue_id');
        $sideByQueueId = array_column($assignments, 'side', 'queue_id');

        if (count(array_unique($queueIds)) !== $size) {
            throw new BusinessRuleException('Duplicate queue entries selected.');
        }

        $perSide = $size / 2;
        $counts = array_count_values($sideByQueueId);
        if (($counts[0] ?? 0) !== $perSide || ($counts[1] ?? 0) !== $perSide) {
            throw new BusinessRuleException('Each side must have the same number of players.');
        }

        return DB::transaction(function () use ($queueIds, $sideByQueueId, $size, $durationMinutes) {
            if (Matchup::pending()->count() >= self::MAX_PENDING) {
                throw new BusinessRuleException('The upcoming matchup queue is full (max 3). Start one first.');
            }

            $entries = Queue::whereIn('id', $queueIds)->lockForUpdate()->get()->keyBy('id');

            if ($entries->count() !== $size) {
                throw new BusinessRuleException('One or more queue entries were not found.');
            }

            foreach ($queueIds as $id) {
                $entry = $entries[$id];

                if (! in_array($entry->status, [QueueStatus::Waiting->value, QueueStatus::Called->value])) {
                    throw new BusinessRuleException("Queue entry #{$entry->queue_number} is not eligible.");
                }

                $alreadyInPending = DB::table('matchup_players')
                    ->join('matchups', 'matchups.id', '=', 'matchup_players.matchup_id')
                    ->where('matchup_players.queue_id', $id)
                    ->where('matchups.status', 'pending')
                    ->exists();

                if ($alreadyInPending) {
                    throw new BusinessRuleException("Queue entry #{$entry->queue_number} is already in an upcoming matchup.");
                }
            }

            $matchup = Matchup::create([
                'match_size' => $size,
                'duration_minutes' => $durationMinutes,
                'status' => 'pending',
            ]);

            foreach ($queueIds as $id) {
                $entry = $entries[$id];

                $matchup->matchupPlayers()->create([
                    'queue_id' => $entry->id,
                    'player_id' => $entry->player_id,
                    'side' => $sideByQueueId[$id],
                ]);

                $entry->update(['status' => QueueStatus::Called->value, 'called_at' => now()]);
            }

            return $matchup->load('matchupPlayers.player');
        });
    }

    /**
     * Auto-form a balanced matchup the same way GameService::smartAssign
     * balances skill, but without picking a court yet.
     */
    public function smartForm(int $size, ?int $durationMinutes = null): array
    {
        if (Matchup::pending()->count() >= self::MAX_PENDING) {
            throw new BusinessRuleException('The upcoming matchup queue is full (max 3). Start one first.');
        }

        $reservedQueueIds = DB::table('matchup_players')
            ->join('matchups', 'matchups.id', '=', 'matchup_players.matchup_id')
            ->where('matchups.status', 'pending')
            ->pluck('matchup_players.queue_id');

        $result = $this->games->exposeSmartAssignments($size, $reservedQueueIds);
        $matchup = $this->form($result['assignments'], $durationMinutes);

        return ['matchup' => $matchup, 'warning' => $result['warning']];
    }

    /**
     * Send a pending matchup onto a now-available court, creating the real game.
     */
    public function start(Matchup $matchup, Court $court): Game
    {
        return DB::transaction(function () use ($matchup, $court) {
            $matchup = Matchup::whereKey($matchup->id)->lockForUpdate()->firstOrFail();

            if ($matchup->status !== 'pending') {
                throw new BusinessRuleException('This matchup is no longer pending.');
            }

            $assignments = $matchup->matchupPlayers()
                ->get(['queue_id', 'side'])
                ->map(fn($mp) => ['queue_id' => $mp->queue_id, 'side' => $mp->side])
                ->toArray();

            $game = $this->games->assign($court, $assignments, $matchup->duration_minutes);

            $matchup->update(['status' => 'started', 'game_id' => $game->id]);

            return $game;
        });
    }

    public function cancel(Matchup $matchup): Matchup
    {
        return DB::transaction(function () use ($matchup) {
            $matchup = Matchup::whereKey($matchup->id)->lockForUpdate()->firstOrFail();

            if ($matchup->status !== 'pending') {
                throw new BusinessRuleException('Only a pending matchup can be cancelled.');
            }

            $matchup->load('matchupPlayers');

            foreach ($matchup->matchupPlayers as $mp) {
                Queue::whereKey($mp->queue_id)
                    ->where('status', QueueStatus::Called->value)
                    ->update(['status' => QueueStatus::Waiting->value, 'called_at' => null]);
            }

            $matchup->update(['status' => 'cancelled']);

            return $matchup;
        });
    }
}
