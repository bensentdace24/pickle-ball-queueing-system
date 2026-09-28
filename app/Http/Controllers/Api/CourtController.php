<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourtStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourtRequest;
use App\Http\Requests\UpdateCourtStatusRequest;
use App\Http\Resources\CourtResource;
use App\Http\Traits\ApiResponse;
use App\Models\Court;

class CourtController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $courts = Court::with('activeGame.gamePlayers.player')->orderBy('id')->get();

        return $this->success(CourtResource::collection($courts));
    }

    public function store(StoreCourtRequest $request)
    {
        $court = Court::create([
            'name' => $request->validated('name'),
            'status' => CourtStatus::Available->value,
        ]);

        return $this->success(new CourtResource($court), 'Court created.', 201);
    }

    public function updateStatus(UpdateCourtStatusRequest $request, Court $court)
    {
        if ($court->status === CourtStatus::Playing->value) {
            throw new BusinessRuleException('Court has an active game. Finish the game first.');
        }

        $court->update(['status' => $request->validated('status')]);

        return $this->success(new CourtResource($court), 'Court status updated.');
    }
}
