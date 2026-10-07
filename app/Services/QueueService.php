<?php

namespace App\Services;

use App\Enums\QueueStatus;
use App\Models\Player;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * Add a player to the queue. Fails if the player already has an
     * active (waiting/called/playing) queue entry — enforced both here
     * and by the DB's partial unique index as a safety net.
     */
    public function join(Player $player, string $matchType = 'any', bool $requiresApproval = false): Queue
    {
        return DB::transaction(function () use ($player, $matchType, $requiresApproval) {
            $existing = $player->queueEntries()
                ->whereIn('status', [
                    QueueStatus::Pending->value,
                    QueueStatus::Waiting->value,
                    QueueStatus::Called->value,
                    QueueStatus::Playing->value,
                ])
                ->exists();

            if ($existing) {
                throw new \RuntimeException('Player already has an active queue entry.');
            }

            $matchType = in_array($matchType, ['any', 'singles', 'doubles'], true) ? $matchType : 'any';

            if ($requiresApproval) {
                return Queue::create([
                    'player_id' => $player->id,
                    'queue_number' => null,
                    'status' => QueueStatus::Pending->value,
                    'match_type' => $matchType,
                    'joined_at' => now(),
                ]);
            }

            return Queue::create([
                'player_id' => $player->id,
                'queue_number' => DB::selectOne("SELECT nextval('queue_number_seq') AS n")->n,
                'status' => QueueStatus::Waiting->value,
                'match_type' => $matchType,
                'joined_at' => now(),
            ]);
        });
    }

    public function pendingList()
    {
        return Queue::with('player')
            ->where('status', QueueStatus::Pending->value)
            ->oldest('joined_at')
            ->get();
    }

    public function approve(Queue $queue): Queue
    {
        return DB::transaction(function () use ($queue) {
            $queue = Queue::whereKey($queue->id)->lockForUpdate()->firstOrFail();

            if ($queue->status !== QueueStatus::Pending->value) {
                throw new \RuntimeException('Only a pending registration can be approved.');
            }

            $queue->update([
                'status' => QueueStatus::Waiting->value,
                'queue_number' => DB::selectOne("SELECT nextval('queue_number_seq') AS n")->n,
                'joined_at' => now(), // fairness resets to the moment they're actually in line
                'approved_at' => now(),
            ]);

            return $queue->load('player');
        });
    }

    public function reject(Queue $queue): Queue
    {
        if ($queue->status !== QueueStatus::Pending->value) {
            throw new \RuntimeException('Only a pending registration can be rejected.');
        }

        $queue->update(['status' => QueueStatus::Cancelled->value]);

        return $queue->load('player');
    }

    /**
     * The active queue, in join order, with the player relationship loaded.
     */
    public function activeQueue()
    {
        return Queue::with('player')
            ->whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
            ->orderedByJoinTime()
            ->get();
    }

    public function cancel(Queue $queue): Queue
    {
        if (in_array($queue->status, [QueueStatus::Playing->value, QueueStatus::Completed->value, QueueStatus::Cancelled->value])) {
            throw new \App\Exceptions\BusinessRuleException('Only a waiting or called queue entry can be cancelled.');
        }

        $queue->update(['status' => QueueStatus::Cancelled->value]);

        return $queue;
    }

    /**
     * Mark the next N waiting players as "called" (does not start a game yet).
     */
    public function callNext(int $count = 4)
    {
        return DB::transaction(function () use ($count) {
            $entries = Queue::whereIn('status', [QueueStatus::Waiting->value])
                ->orderedByJoinTime()
                ->lockForUpdate()
                ->limit($count)
                ->get();

            foreach ($entries as $entry) {
                $entry->update([
                    'status' => QueueStatus::Called->value,
                    'called_at' => now(),
                ]);
            }

            return $entries;
        });
    }
}
