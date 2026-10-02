<?php

namespace App\Services;

use App\Enums\CourtStatus;
use App\Enums\GameStatus;
use App\Enums\QueueStatus;
use App\Enums\ScoreResult;
use App\Exceptions\BusinessRuleException;
use App\Models\Court;
use App\Models\Game;
use App\Models\Queue;
use App\Models\Score;
use Illuminate\Support\Facades\DB;

class GameService
{
    /**
     * Assign players to a court and start a game.
     *
     * @param  array<int, array{queue_id:int, side:int}>  $assignments
     */
    public function assign(Court $court, array $assignments, ?int $durationMinutes = null): Game
    {
        $size = count($assignments);

        if (! in_array($size, [2, 4], true)) {
            throw new BusinessRuleException('A game requires exactly 2 players (singles) or 4 players (doubles).');
        }

        $queueIds = array_column($assignments, 'queue_id');
        $sideByQueueId = array_column($assignments, 'side', 'queue_id');

        if (count(array_unique($queueIds)) !== $size) {
            throw new BusinessRuleException('Duplicate queue entries selected.');
        }

        $perSide = $size / 2;
        $sideCounts = array_count_values($sideByQueueId);
        if (($sideCounts[0] ?? 0) !== $perSide || ($sideCounts[1] ?? 0) !== $perSide) {
            throw new BusinessRuleException('Each side must have the same number of players.');
        }

        return DB::transaction(function () use ($court, $queueIds, $sideByQueueId, $size, $durationMinutes) {
            $court = Court::whereKey($court->id)->lockForUpdate()->firstOrFail();

            if ($court->status !== CourtStatus::Available->value) {
                throw new BusinessRuleException('Court is not available.');
            }

            $entries = Queue::whereIn('id', $queueIds)->lockForUpdate()->get()->keyBy('id');

            if ($entries->count() !== $size) {
                throw new BusinessRuleException('One or more queue entries were not found.');
            }

            foreach ($queueIds as $id) {
                $entry = $entries[$id];
                if (! in_array($entry->status, [QueueStatus::Waiting->value, QueueStatus::Called->value])) {
                    throw new BusinessRuleException("Queue entry #{$entry->queue_number} is not eligible (status: {$entry->status}).");
                }
            }

            $playerIds = $entries->pluck('player_id')->all();
            $alreadyPlaying = DB::table('game_players')
                ->join('games', 'games.id', '=', 'game_players.game_id')
                ->whereIn('game_players.player_id', $playerIds)
                ->where('games.status', GameStatus::Playing->value)
                ->exists();

            if ($alreadyPlaying) {
                throw new BusinessRuleException('One or more selected players are already in an active game.');
            }

            $game = Game::create([
                'court_id' => $court->id,
                'status' => GameStatus::Playing->value,
                'started_at' => now(),
                'duration_minutes' => $durationMinutes,
            ]);

            foreach ($queueIds as $id) {
                $entry = $entries[$id];

                $game->gamePlayers()->create([
                    'player_id' => $entry->player_id,
                    'queue_id' => $entry->id,
                    'side' => $sideByQueueId[$id],
                ]);

                $entry->update(['status' => QueueStatus::Playing->value]);
            }

            $court->update(['status' => CourtStatus::Playing->value]);

            return $game->load('gamePlayers.player', 'court', 'scores');
        });
    }
    /**
     * Auto-pick the oldest N eligible queue entries (fairness preserved —
     * we never skip the line) and split them into balanced teams using a
     * snake draft on skill level, with win rate as a tiebreaker once a
     * player has match history.
     */
    public function smartAssign(Court $court, int $size, ?int $durationMinutes = null): Game
    {
        if (! in_array($size, [2, 4], true)) {
            throw new BusinessRuleException('A game requires exactly 2 players (singles) or 4 players (doubles).');
        }

        $pool = Queue::with('player')
            ->whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
            ->orderedByJoinTime()
            ->limit($size)
            ->get();

        if ($pool->count() < $size) {
            throw new BusinessRuleException("Not enough players in the queue for a {$size}-player game.");
        }

        $skillWeight = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];

        $winRates = DB::table('scores')
            ->join('game_players', function ($join) {
                $join->on('game_players.game_id', '=', 'scores.game_id')
                    ->on('game_players.side', '=', 'scores.side');
            })
            ->whereIn('game_players.player_id', $pool->pluck('player_id'))
            ->select(
                'game_players.player_id',
                DB::raw("COUNT(*) FILTER (WHERE scores.result = 'win')::float / COUNT(*) as win_rate"),
            )
            ->groupBy('game_players.player_id')
            ->pluck('win_rate', 'player_id');

        $ranked = $pool->sortByDesc(function ($entry) use ($skillWeight, $winRates) {
            $skill = $skillWeight[$entry->player->skill_level] ?? 2; // unknown skill = assume average
            $winRate = $winRates[$entry->player_id] ?? 0.5; // no history yet = assume average

            return $skill + (($winRate - 0.5) * 0.5); // skill dominates; record nudges ties
        })->values();

        // Snake draft so the two teams end up balanced: 1st & last pick go
        // together, the two middle picks go together.
        $assignments = [];
        foreach ($ranked as $i => $entry) {
            $round = intdiv($i, 2);
            $side = ($round % 2 === 0) ? ($i % 2) : (1 - $i % 2);
            $assignments[] = ['queue_id' => $entry->id, 'side' => $side];
        }

        return $this->assign($court, $assignments, $durationMinutes);
    }

    public function finish(Game $game, int $teamAScore, int $teamBScore): Game
    {
        return DB::transaction(function () use ($game, $teamAScore, $teamBScore) {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($game->status !== GameStatus::Playing->value) {
                throw new BusinessRuleException('Game is not currently active.');
            }

            $game->update([
                'status' => GameStatus::Completed->value,
                'completed_at' => now(),
            ]);

            $resultFor = fn(int $mine, int $theirs) => $mine > $theirs
                ? ScoreResult::Win->value
                : ($mine < $theirs ? ScoreResult::Loss->value : ScoreResult::Draw->value);

            Score::create([
                'game_id' => $game->id,
                'side' => 0,
                'points' => $teamAScore,
                'result' => $resultFor($teamAScore, $teamBScore),
            ]);

            Score::create([
                'game_id' => $game->id,
                'side' => 1,
                'points' => $teamBScore,
                'result' => $resultFor($teamBScore, $teamAScore),
            ]);

            $game->load('gamePlayers.queueEntry');

            foreach ($game->gamePlayers as $gamePlayer) {
                $queueEntry = $gamePlayer->queueEntry;
                if ($queueEntry) {
                    $queueEntry->update(['status' => $this->resolveQueueStatusAfterGame()]);
                }
            }

            $game->court()->update(['status' => CourtStatus::Available->value]);

            return $game->load('gamePlayers.player', 'court', 'scores');
        });
    }

    protected function resolveQueueStatusAfterGame(): string
    {
        return QueueStatus::Completed->value;
    }
}
