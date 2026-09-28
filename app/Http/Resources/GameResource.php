<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        return [
            'id' => $this->id,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'court' => new CourtResource($this->whenLoaded('court')),
            'players' => PlayerResource::collection(
                $this->whenLoaded('gamePlayers', fn() => $this->gamePlayers->map->player)
            ),
        ];
    }
}
