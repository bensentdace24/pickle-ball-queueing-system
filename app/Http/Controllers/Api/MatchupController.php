<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FormMatchupRequest;
use App\Http\Requests\SmartFormMatchupRequest;
use App\Http\Requests\StartMatchupRequest;
use App\Http\Resources\GameResource;
use App\Http\Resources\MatchupResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;
use App\Models\Matchup;
use App\Services\MatchupService;
use App\Services\WaitEstimator;

class MatchupController extends Controller
{
    use ApiResponse;

    public function __construct(private MatchupService $matchups, private WaitEstimator $estimator) {}

    public function index()
    {
        $pending = Matchup::with('matchupPlayers.player')->pending()->get();

        $estimates = $this->estimator->build();
        $pending->each(fn($m) => $m->setAttribute('estimated_minutes', $estimates['matchups'][$m->id] ?? null));

        return $this->success(MatchupResource::collection($pending));
    }

    public function store(FormMatchupRequest $request)
    {
        $matchup = $this->matchups->form($request->validated('assignments'), $request->validated('duration_minutes'));

        return $this->success(new MatchupResource($matchup), 'Matchup formed.', 201);
    }

    public function smart(SmartFormMatchupRequest $request)
    {
        $result = $this->matchups->smartForm($request->validated('match_size'), $request->validated('duration_minutes'));
        $message = $result['warning'] ?? 'Balanced matchup formed.';

        return $this->success(new MatchupResource($result['matchup']), $message, 201);
    }

    public function start(StartMatchupRequest $request, Matchup $matchup)
    {
        $court = $request->validated('court_id')
            ? Court::findOrFail($request->validated('court_id'))
            : null;

        $game = $this->matchups->start($matchup, $court);

        return $this->success(new GameResource($game), 'Matchup started.');
    }

    public function cancel(Matchup $matchup)
    {
        $matchup = $this->matchups->cancel($matchup);

        return $this->success(new MatchupResource($matchup->load('matchupPlayers.player')), 'Matchup cancelled.');
    }
}
