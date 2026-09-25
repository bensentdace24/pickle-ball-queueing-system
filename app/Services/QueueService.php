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
    public function join(Player $player): Queue
    {
        return DB::transaction(function () use ($player) {
            $existing = $player->queueEntries()
                ->whereIn('status', [
                    QueueStatus::Waiting->value,
                    QueueStatus::Called->value,
                    QueueStatus::Playing->value,
                ])
                ->exists();

            if ($existing) {
                throw new \RuntimeException('Player already has an active queue entry.');
            }

            return Queue::create([
                'player_id' => $player->id,
                'queue_number' => DB::selectOne("SELECT nextval('queue_number_seq') AS n")->n,
                'status' => QueueStatus::Waiting->value,
                'joined_at' => now(),
            ]);
        });
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
            throw new \RuntimeException('Only a waiting or called queue entry can be cancelled.');
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
