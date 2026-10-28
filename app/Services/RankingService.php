<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RankingService
{
    public function __construct(private RankService $ranks) {}

    /**
     * Leaderboard order:
     *   1. highest win percentage
     *   2. biggest point difference (points scored minus points conceded), so an
     *      11-6 win counts for more than an 11-10 win
     *   3. most wins, then points scored and faster average game time
     */
    public function all(): array
    {
        $rows = DB::table('scores as mine')
            ->join('scores as theirs', function ($join) {
                $join->on('theirs.game_id', '=', 'mine.game_id')
                    ->on('theirs.side', '!=', 'mine.side');
            })
            ->join('game_players', function ($join) {
                $join->on('game_players.game_id', '=', 'mine.game_id')
                    ->on('game_players.side', '=', 'mine.side');
            })
            ->join('games', 'games.id', '=', 'mine.game_id')
            ->join('players', 'players.id', '=', 'game_players.player_id')
            ->where('games.status', 'completed')
            ->select(
                'players.id as player_id',
                'players.name',
                'players.rank_points',
                DB::raw('SUM(mine.points) as total_points'),
                DB::raw('SUM(mine.points - theirs.points) as point_diff'),
                DB::raw("COUNT(*) FILTER (WHERE mine.result = 'win') as wins"),
                DB::raw("COUNT(*) FILTER (WHERE mine.result = 'loss') as losses"),
                DB::raw("COUNT(*) FILTER (WHERE mine.result = 'draw') as draws"),
                DB::raw('COUNT(*) as games_played'),
                DB::raw('AVG(EXTRACT(EPOCH FROM (games.completed_at - games.started_at))) as avg_seconds'),
            )
            ->groupBy('players.id', 'players.name', 'players.rank_points')
            ->orderByRaw("COUNT(*) FILTER (WHERE mine.result = 'win')::decimal / NULLIF(COUNT(*), 0) DESC")
            ->orderByDesc('point_diff')
            ->orderByDesc('wins')
            ->orderByDesc('total_points')
            ->orderBy('avg_seconds')
            ->get();

        $total = $rows->count();

        return $rows->values()->map(function ($row, $i) use ($total) {
            $games = (int) $row->games_played;
            $wins = (int) $row->wins;

            return [
                'rank' => $i + 1,
                'player_id' => $row->player_id,
                'name' => $row->name,
                'tier' => $this->ranks->summary((int) $row->rank_points)['tier'],
                'rank_points' => (int) $row->rank_points,
                'total_points' => (int) $row->total_points,
                'point_diff' => (int) $row->point_diff,
                'wins' => $wins,
                'losses' => (int) $row->losses,
                'draws' => (int) $row->draws,
                'games_played' => $games,
                'win_rate' => $games > 0 ? round($wins / $games * 100, 1) : 0,
                'top_percent' => (int) ceil((($i + 1) / $total) * 100),
                'avg_seconds' => $row->avg_seconds !== null ? (int) round($row->avg_seconds) : null,
            ];
        })->all();
    }
}
