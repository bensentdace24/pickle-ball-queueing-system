<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignGameRequest;
use App\Http\Resources\GameResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\Game;
use App\Services\GameService;
use Illuminate\Http\Request;
use App\Http\Requests\FinishGameRequest;
use App\Http\Requests\SmartAssignRequest;

class GameController extends Controller
{
    use ApiResponse;

    public function __construct(private GameService $games) {}

    // ?status=playing (default) | completed | cancelled | all
    public function index(Request $request)
    {
        $status = $request->query('status', 'playing');
        $allowed = ['playing', 'completed', 'cancelled', 'all'];
        $status = in_array($status, $allowed, true) ? $status : 'playing';
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $games = Game::with('court', 'gamePlayers.player', 'scores')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($request->query('player'), fn($q, $name) => $q->whereHas(
                'gamePlayers.player',
                fn($p) => $p->where('name', 'ilike', "%{$name}%")
            ))
            ->orderByDesc($status === 'completed' ? 'completed_at' : 'started_at')
            ->limit($limit)
            ->get();

        return $this->success(GameResource::collection($games));
    }

    // Assign 4 queue entries to a court. This also starts the game.
    public function store(AssignGameRequest $request)
    {
        $court = Court::findOrFail($request->validated('court_id'));
        $game = $this->games->assign(
            $court,
            $request->validated('assignments'),
            $request->validated('duration_minutes'),
        );

        return $this->success(new GameResource($game), 'Game started.', 201);
    }


    public function finish(FinishGameRequest $request, Game $game)
    {
        $game = $this->games->finish(
            $game,
            $request->validated('team_a_score'),
            $request->validated('team_b_score'),
        );

        return $this->success(new GameResource($game), 'Game finished.');
    }

    public function smartAssign(SmartAssignRequest $request)
    {
        $court = Court::findOrFail($request->validated('court_id'));
        $result = $this->games->smartAssign(
            $court,
            $request->validated('match_size'),
            $request->validated('duration_minutes'),
        );

        $message = $result['warning'] ?? 'Game started with balanced teams.';

        return $this->success(new GameResource($result['game']), $message, 201);
    }
}
