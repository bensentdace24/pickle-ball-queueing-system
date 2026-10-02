<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class RankingController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $rows = DB::table('scores')
            ->join('game_players', function ($join) {
                $join->on('game_players.game_id', '=', 'scores.game_id')
                    ->on('game_players.side', '=', 'scores.side');
            })
            ->join('games', 'games.id', '=', 'scores.game_id')
            ->join('players', 'players.id', '=', 'game_players.player_id')
            ->where('games.status', 'completed')
            ->select(
                'players.id as player_id',
                'players.name',
                DB::raw('SUM(scores.points) as total_points'),
                DB::raw("COUNT(*) FILTER (WHERE scores.result = 'win') as wins"),
                DB::raw("COUNT(*) FILTER (WHERE scores.result = 'loss') as losses"),
                DB::raw("COUNT(*) FILTER (WHERE scores.result = 'draw') as draws"),
                DB::raw('COUNT(*) as games_played'),
                DB::raw('AVG(EXTRACT(EPOCH FROM (games.completed_at - games.started_at))) as avg_seconds'),
            )
            ->groupBy('players.id', 'players.name')
            ->orderByDesc('wins')
            ->orderByDesc('total_points')
            ->orderBy('avg_seconds')
            ->get();

        $ranked = $rows->values()->map(fn($row, $i) => [
            'rank' => $i + 1,
            'player_id' => $row->player_id,
            'name' => $row->name,
            'total_points' => (int) $row->total_points,
            'wins' => (int) $row->wins,
            'losses' => (int) $row->losses,
            'draws' => (int) $row->draws,
            'games_played' => (int) $row->games_played,
            'avg_seconds' => $row->avg_seconds !== null ? (int) round($row->avg_seconds) : null,
        ]);

        return $this->success($ranked);
    }
}
