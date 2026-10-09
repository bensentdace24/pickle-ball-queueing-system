<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\RankingService;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use ApiResponse;

    // Time stats ignore games under 1 min or over 3 hours (test clicks or forgotten games).
    private const MIN_REAL_GAME_SECONDS = 60;
    private const MAX_REAL_GAME_SECONDS = 10800;

    public function __construct(private RankingService $rankings) {}

    public function index()
    {
        $totalGames = DB::table('games')->where('status', 'completed')->count();

        $totalPlayers = DB::table('game_players')
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->where('games.status', 'completed')
            ->distinct()
            ->count('game_players.player_id');

        $sizes = DB::table('game_players')
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->where('games.status', 'completed')
            ->groupBy('game_players.game_id')
            ->selectRaw('COUNT(*) as n')
            ->pluck('n');

        $time = DB::table('games')
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->whereRaw(
                'EXTRACT(EPOCH FROM (completed_at - started_at)) BETWEEN ? AND ?',
                [self::MIN_REAL_GAME_SECONDS, self::MAX_REAL_GAME_SECONDS]
            )
            ->selectRaw('
                COUNT(*) as counted,
                AVG(EXTRACT(EPOCH FROM (completed_at - started_at))) as avg_s,
                MIN(EXTRACT(EPOCH FROM (completed_at - started_at))) as min_s,
                MAX(EXTRACT(EPOCH FROM (completed_at - started_at))) as max_s
            ')
            ->first();

        $scores = DB::table('games')
            ->join('scores as a', fn($j) => $j->on('a.game_id', '=', 'games.id')->where('a.side', 0))
            ->join('scores as b', fn($j) => $j->on('b.game_id', '=', 'games.id')->where('b.side', 1))
            ->where('games.status', 'completed')
            ->selectRaw('
                COUNT(*) FILTER (WHERE ABS(a.points - b.points) <= 2) as close,
                COUNT(*) FILTER (WHERE ABS(a.points - b.points) BETWEEN 3 AND 5) as moderate,
                COUNT(*) FILTER (WHERE ABS(a.points - b.points) >= 6) as lopsided,
                AVG(GREATEST(a.points, b.points)) as avg_winning,
                AVG(LEAST(a.points, b.points)) as avg_losing
            ')
            ->first();

        $round = fn($v) => $v !== null ? (int) round($v) : null;

        return $this->success([
            'total_games' => $totalGames,
            'total_players' => $totalPlayers,
            'singles_games' => $sizes->filter(fn($n) => (int) $n === 2)->count(),
            'doubles_games' => $sizes->filter(fn($n) => (int) $n === 4)->count(),
            'time' => [
                'counted_games' => (int) $time->counted,
                'avg_seconds' => $round($time->avg_s),
                'fastest_seconds' => $round($time->min_s),
                'longest_seconds' => $round($time->max_s),
            ],
            'scores' => [
                'close' => (int) $scores->close,
                'moderate' => (int) $scores->moderate,
                'lopsided' => (int) $scores->lopsided,
                'avg_winning' => $scores->avg_winning !== null ? round($scores->avg_winning, 1) : null,
                'avg_losing' => $scores->avg_losing !== null ? round($scores->avg_losing, 1) : null,
            ],
            'top_players' => array_slice($this->rankings->all(), 0, 5),
        ]);
    }
}
