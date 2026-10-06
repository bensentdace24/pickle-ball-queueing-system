<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Resources\PlayerResource;
use App\Http\Traits\ApiResponse;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $players = Player::query()
            ->when($request->query('search'), fn($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->limit(100)
            ->get();

        return $this->success(PlayerResource::collection($players));
    }

    public function store(StorePlayerRequest $request)
    {
        $player = Player::create($request->validated());

        return $this->success(new PlayerResource($player), 'Player registered.', 201);
    }

    public function show(Player $player)
    {
        $stats = DB::table('scores')
            ->join('game_players', function ($join) {
                $join->on('game_players.game_id', '=', 'scores.game_id')
                    ->on('game_players.side', '=', 'scores.side');
            })
            ->join('games', 'games.id', '=', 'scores.game_id')
            ->where('game_players.player_id', $player->id)
            ->where('games.status', 'completed')
            ->selectRaw("
            COUNT(*) as games_played,
            COUNT(*) FILTER (WHERE scores.result = 'win') as wins,
            COUNT(*) FILTER (WHERE scores.result = 'loss') as losses,
            COUNT(*) FILTER (WHERE scores.result = 'draw') as draws,
            COALESCE(SUM(scores.points), 0) as total_points
        ")
            ->first();

        $activeQueue = $player->activeQueueEntry();

        return $this->success([
            'player' => new PlayerResource($player),
            'stats' => [
                'games_played' => (int) $stats->games_played,
                'wins' => (int) $stats->wins,
                'losses' => (int) $stats->losses,
                'draws' => (int) $stats->draws,
                'total_points' => (int) $stats->total_points,
            ],
            'currently_in_queue' => $activeQueue !== null,
            'current_status' => $activeQueue?->status,
        ]);
    }
}
