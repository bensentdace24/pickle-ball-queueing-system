<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Resources\PlayerResource;
use App\Http\Traits\ApiResponse;
use App\Models\Player;
use Illuminate\Http\Request;

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
        return $this->success(new PlayerResource($player));
    }
}
