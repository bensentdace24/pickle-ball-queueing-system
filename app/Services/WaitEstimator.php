<?php

namespace App\Services;

use App\Enums\CourtStatus;
use App\Enums\QueueStatus;
use App\Models\Court;
use App\Models\Matchup;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

class WaitEstimator
{
    public const DEFAULT_MINUTES = 30;
    private const MIN_REAL_GAME_SECONDS = 300; // ignore quick test games under 5 min
    private const MAX_MINUTES = 120;

    /**
     * Average length of past completed games, or the default when there's no real history.
     */


    private const MAX_REAL_GAME_SECONDS = 5400; // ignore games left open longer than 90 min (forgotten or test data)



    public function averageMinutes(): int
    {
        $seconds = DB::table('games')
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->whereRaw('EXTRACT(EPOCH FROM (completed_at - started_at)) BETWEEN ? AND ?', [self::MIN_REAL_GAME_SECONDS, self::MAX_REAL_GAME_SECONDS])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (completed_at - started_at))) as s')
            ->value('s');

        if ($seconds === null) {
            return self::DEFAULT_MINUTES;
        }

        return (int) min(self::MAX_MINUTES, max(5, round($seconds / 60)));
    }

    /**
     * Simulates the line: every usable court has a "free in X minutes" time,
     * and each match ahead (pending matchups first, then waiting players in
     * join order, grouped by 4) takes the earliest free court.
     *
     * @return array{average_minutes:int, matchups:array<int,int>, queue:array<int,int>}
     */
    public function build(): array
    {
        $avg = $this->averageMinutes();
        $result = ['average_minutes' => $avg, 'matchups' => [], 'queue' => []];

        $free = [];
        $courts = Court::with('activeGame')
            ->where('status', '!=', CourtStatus::Maintenance->value)
            ->get();

        foreach ($courts as $court) {
            $game = $court->activeGame;

            if ($court->status === CourtStatus::Playing->value && $game && $game->started_at) {
                $end = $game->started_at->copy()->addMinutes($game->duration_minutes ?? $avg);
                $free[] = max(0, (int) ceil(($end->getTimestamp() - now()->getTimestamp()) / 60));
            } else {
                $free[] = 0;
            }
        }

        if (empty($free)) {
            return $result; // no usable courts, so no honest estimate
        }

        $reserved = [];

        foreach (Matchup::pending()->with('matchupPlayers')->get() as $matchup) {
            sort($free);
            $start = array_shift($free);

            $result['matchups'][$matchup->id] = $start;

            foreach ($matchup->matchupPlayers as $mp) {
                $result['queue'][$mp->queue_id] = $start;
                $reserved[] = $mp->queue_id;
            }

            $free[] = $start + ($matchup->duration_minutes ?? $avg);
        }

        $waiting = Queue::whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
            ->whereNotIn('id', $reserved)
            ->orderedByJoinTime()
            ->pluck('id');

        foreach ($waiting->chunk(4) as $group) {
            sort($free);
            $start = array_shift($free);

            foreach ($group as $id) {
                $result['queue'][$id] = $start;
            }

            $free[] = $start + $avg;
        }

        return $result;
    }
}
