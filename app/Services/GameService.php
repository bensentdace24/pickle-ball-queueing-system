<?php

namespace App\Services;

use App\Enums\CourtStatus;
use App\Enums\GameStatus;
use App\Enums\QueueStatus;
use App\Models\Court;
use App\Models\Game;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class GameService
{
    /**
     * Assign exactly 4 eligible queue entries to an available court,
     * creating the game + game_players records and flipping statuses.
     *
     * @param  array<int>  $queueIds  Exactly 4 queue entry IDs.
     */
    public function assign(Court $court, array $queueIds): Game
    {
        if (count($queueIds) !== 4) {
            throw new \RuntimeException('A game requires exactly 4 players.');
        }

        if (count(array_unique($queueIds)) !== 4) {
            throw new \RuntimeException('Duplicate queue entries selected.');
        }

        return DB::transaction(function () use ($court, $queueIds) {
            // Lock the court row so two staff clicking at the same time can't
            // both assign a game to it.
            $court = Court::whereKey($court->id)->lockForUpdate()->firstOrFail();

            if ($court->status !== CourtStatus::Available->value) {
                throw new \RuntimeException('Court is not available.');
            }

            // Lock the queue rows we're about to consume.
            $entries = Queue::whereIn('id', $queueIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($entries->count() !== 4) {
                throw new \RuntimeException('One or more queue entries were not found.');
            }

            foreach ($queueIds as $id) {
                $entry = $entries[$id];

                if (! in_array($entry->status, [QueueStatus::Waiting->value, QueueStatus::Called->value])) {
                    throw new \RuntimeException("Queue entry #{$entry->queue_number} is not eligible (status: {$entry->status}).");
                }
            }

            // A player can't already be in another currently-playing game.
            $playerIds = $entries->pluck('player_id')->all();
            $alreadyPlaying = DB::table('game_players')
                ->join('games', 'games.id', '=', 'game_players.game_id')
                ->whereIn('game_players.player_id', $playerIds)
                ->where('games.status', GameStatus::Playing->value)
                ->exists();

            if ($alreadyPlaying) {
                throw new \RuntimeException('One or more selected players are already in an active game.');
            }

            $game = Game::create([
                'court_id' => $court->id,
                'status' => GameStatus::Playing->value,
                'started_at' => now(),
            ]);

            foreach ($queueIds as $id) {
                $entry = $entries[$id];

                $game->gamePlayers()->create([
                    'player_id' => $entry->player_id,
                    'queue_id' => $entry->id,
                ]);

                $entry->update(['status' => QueueStatus::Playing->value]);
            }

            $court->update(['status' => CourtStatus::Playing->value]);

            return $game->load('gamePlayers.player', 'court');
        });
    }

    /**
     * Mark a game finished, free the court, and resolve each player's
     * queue status. The "should completed players rejoin the queue"
     * decision is isolated in resolveQueueStatusAfterGame() so it stays
     * easy to change later without touching the rest of this method.
     */
    public function finish(Game $game): Game
    {
        return DB::transaction(function () use ($game) {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($game->status !== GameStatus::Playing->value) {
                throw new \RuntimeException('Game is not currently active.');
            }

            $game->update([
                'status' => GameStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $game->load('gamePlayers.queueEntry');

            foreach ($game->gamePlayers as $gamePlayer) {
                $queueEntry = $gamePlayer->queueEntry;
                if ($queueEntry) {
                    $queueEntry->update([
                        'status' => $this->resolveQueueStatusAfterGame(),
                    ]);
                }
            }

            $game->court()->update(['status' => CourtStatus::Available->value]);

            return $game->load('gamePlayers.player', 'court');
        });
    }

    /**
     * Central place to decide what happens to a queue entry once its game
     * ends. Currently: mark it Completed (does NOT auto re-queue the
     * player). Swap this later if the business rule changes.
     */
    protected function resolveQueueStatusAfterGame(): string
    {
        return QueueStatus::Completed->value;
    }
}
