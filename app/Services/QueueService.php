<?php

namespace App\Services;

use App\Enums\QueueStatus;
use App\Models\Player;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessRuleException;

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
                throw new \App\Exceptions\BusinessRuleException('Player already has an active queue entry.');
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
                throw new \App\Exceptions\BusinessRuleException('Only a pending registration can be approved.');
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
            throw new \App\Exceptions\BusinessRuleException('Only a pending registration can be rejected.');
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

    /**
     * Finds existing players that look like the same person as each pending
     * registration (same name ignoring case/spaces, or same phone digits).
     */
    public function attachPossibleMatches($entries): void
    {
        foreach ($entries as $entry) {
            $player = $entry->player;
            $name = mb_strtolower(trim($player->name));
            $digits = preg_replace('/\D+/', '', (string) $player->phone);

            $matches = Player::where('id', '!=', $player->id)
                ->where(function ($q) use ($name, $digits) {
                    $q->whereRaw('LOWER(TRIM(name)) = ?', [$name]);

                    if (strlen($digits) >= 7) {
                        $q->orWhereRaw("regexp_replace(COALESCE(phone, ''), '\\D', '', 'g') = ?", [$digits]);
                    }
                })
                ->limit(3)
                ->get();

            $ids = $matches->pluck('id');

            $stats = DB::table('scores')
                ->join('game_players', function ($join) {
                    $join->on('game_players.game_id', '=', 'scores.game_id')
                        ->on('game_players.side', '=', 'scores.side');
                })
                ->join('games', 'games.id', '=', 'scores.game_id')
                ->whereIn('game_players.player_id', $ids)
                ->where('games.status', 'completed')
                ->groupBy('game_players.player_id')
                ->selectRaw("
                game_players.player_id,
                COUNT(*) FILTER (WHERE scores.result = 'win') as wins,
                COUNT(*) FILTER (WHERE scores.result = 'loss') as losses,
                COUNT(*) FILTER (WHERE scores.result = 'draw') as draws
            ")
                ->get()
                ->keyBy('player_id');

            $active = Queue::whereIn('player_id', $ids)
                ->whereIn('status', ['pending', 'waiting', 'called', 'playing'])
                ->pluck('status', 'player_id');

            $entry->setAttribute('possible_matches', $matches->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'skill_level' => $p->skill_level,
                'wins' => (int) ($stats[$p->id]->wins ?? 0),
                'losses' => (int) ($stats[$p->id]->losses ?? 0),
                'draws' => (int) ($stats[$p->id]->draws ?? 0),
                'active_status' => $active[$p->id] ?? null,
            ])->all());
        }
    }

    /**
     * Moves a pending registration onto an existing player and removes the
     * duplicate player record (only if it has no other history).
     */
    public function linkToPlayer(Queue $queue, Player $target): Queue
    {
        return DB::transaction(function () use ($queue, $target) {
            $queue = Queue::whereKey($queue->id)->lockForUpdate()->firstOrFail();

            if ($queue->status !== QueueStatus::Pending->value) {
                throw new BusinessRuleException('Only a pending registration can be linked.');
            }

            if ($queue->player_id === $target->id) {
                throw new BusinessRuleException('This registration already belongs to that player.');
            }

            $hasActive = Queue::where('player_id', $target->id)
                ->whereIn('status', ['pending', 'waiting', 'called', 'playing'])
                ->exists();

            if ($hasActive) {
                throw new BusinessRuleException("{$target->name} already has an active queue entry.");
            }

            $duplicate = Player::find($queue->player_id);

            $queue->update(['player_id' => $target->id]);

            if ($duplicate && ! $duplicate->queueEntries()->exists() && ! $duplicate->gamePlayers()->exists()) {
                $duplicate->delete();
            }

            return $queue->load('player');
        });
    }
}
