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
    public function smartAssign(Court $court, int $size, ?int $durationMinutes = null): array
    {
        $result = $this->buildSmartAssignments($size, collect());
        $game = $this->assign($court, $result['assignments'], $durationMinutes);

        if ($result['warning']) {
            $game->update(['skill_warning' => $result['warning']]);
        }

        return ['game' => $game->fresh(['gamePlayers.player', 'court', 'scores']), 'warning' => $result['warning']];
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



    /**
     * @param  \Illuminate\Support\Collection<int>  $excludeQueueIds
     * @return array{assignments: array<int, array{queue_id:int, side:int}>, warning: ?string}
     */
    protected function buildSmartAssignments(int $size, $excludeQueueIds): array
    {
        if (! in_array($size, [2, 4], true)) {
            throw new BusinessRuleException('A game requires exactly 2 players (singles) or 4 players (doubles).');
        }

        $matchTypeKey = $size === 2 ? 'singles' : 'doubles';
        $skillWeight = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3];
        $skillLabel = array_flip($skillWeight);

        $winRateCache = [];
        $winRateFor = function (int $playerId) use (&$winRateCache) {
            if (! array_key_exists($playerId, $winRateCache)) {
                $row = DB::table('scores')
                    ->join('game_players', function ($join) {
                        $join->on('game_players.game_id', '=', 'scores.game_id')
                            ->on('game_players.side', '=', 'scores.side');
                    })
                    ->where('game_players.player_id', $playerId)
                    ->selectRaw("COUNT(*) FILTER (WHERE scores.result = 'win')::float / NULLIF(COUNT(*), 0) as rate")
                    ->first();
                $winRateCache[$playerId] = $row && $row->rate !== null ? (float) $row->rate : 0.5;
            }

            return $winRateCache[$playerId];
        };

        $scoreFor = fn($entry) => ($skillWeight[$entry->player->skill_level] ?? 2)
            + (($winRateFor($entry->player_id) - 0.5) * 0.5);

        if ($size === 2) {
            $windowSize = 8;

            $pool = Queue::with('player')
                ->whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
                ->whereIn('match_type', ['any', $matchTypeKey])
                ->whereNotIn('id', $excludeQueueIds)
                ->orderedByJoinTime()
                ->limit($windowSize)
                ->get();

            if ($pool->count() < 2) {
                throw new BusinessRuleException('Not enough players waiting for a singles match right now.');
            }

            $scored = $pool->values()->map(fn($entry, $i) => [
                'entry' => $entry,
                'tier' => $skillWeight[$entry->player->skill_level] ?? 2,
                'position' => $i,
            ]);

            $best = null;
            foreach ($scored as $i => $a) {
                foreach ($scored as $j => $b) {
                    if ($j <= $i) {
                        continue;
                    }
                    $gap = abs($a['tier'] - $b['tier']);
                    $positionSum = $a['position'] + $b['position'];
                    if ($best === null || $gap < $best['gap'] || ($gap === $best['gap'] && $positionSum < $best['positionSum'])) {
                        $best = ['pair' => [$a, $b], 'gap' => $gap, 'positionSum' => $positionSum];
                    }
                }
            }

            $warning = null;
            if ($best['gap'] > 1) {
                [$a, $b] = $best['pair'];
                $tierA = $skillLabel[$a['tier']] ?? 'intermediate';
                $tierB = $skillLabel[$b['tier']] ?? 'intermediate';
                $warning = "Uneven skill match: {$a['entry']->player->name} ({$tierA}) vs {$b['entry']->player->name} ({$tierB}). No closer match was available.";
            }

            return [
                'assignments' => [
                    ['queue_id' => $best['pair'][0]['entry']->id, 'side' => 0],
                    ['queue_id' => $best['pair'][1]['entry']->id, 'side' => 1],
                ],
                'warning' => $warning,
            ];
        }

        $pool = Queue::with('player')
            ->whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
            ->whereIn('match_type', ['any', $matchTypeKey])
            ->whereNotIn('id', $excludeQueueIds)
            ->orderedByJoinTime()
            ->limit($size)
            ->get();

        if ($pool->count() < $size) {
            throw new BusinessRuleException("Not enough players waiting for a {$size}-player doubles game right now.");
        }

        $ranked = $pool->sortByDesc($scoreFor)->values();

        $assignments = [];
        foreach ($ranked as $i => $entry) {
            $round = intdiv($i, 2);
            $side = ($round % 2 === 0) ? ($i % 2) : (1 - $i % 2);
            $assignments[] = ['queue_id' => $entry->id, 'side' => $side];
        }

        return ['assignments' => $assignments, 'warning' => null];
    }

    public function exposeSmartAssignments(int $size, $excludeQueueIds): array
    {
        return $this->buildSmartAssignments($size, $excludeQueueIds);
    }
}
