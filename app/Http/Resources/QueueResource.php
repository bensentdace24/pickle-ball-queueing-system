<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'queue_number' => $this->queue_number,
            'status' => $this->status,
            'joined_at' => $this->joined_at,
            'called_at' => $this->called_at,
            'player' => new PlayerResource($this->whenLoaded('player')),
            // filled in only when this queue entry is requested with its
            // position computed by the controller (see below)
            'position' => $this->getAttribute('position'),
            'game' => $this->whenLoaded('gamePlayer', fn() => $this->gamePlayer?->game
                ? new GameResource($this->gamePlayer->game)
                : null),
        ];
    }
}
