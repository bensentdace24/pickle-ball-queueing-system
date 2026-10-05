<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource) {
            return [];
        }

        $players = $this->relationLoaded('gamePlayers') ? $this->gamePlayers : collect();
        $scores = $this->relationLoaded('scores') ? $this->scores->keyBy('side') : collect();

        $team = function (int $side) use ($players, $scores) {
            $sidePlayers = $players->where('side', $side)->map->player->values();
            $score = $scores->get($side);

            return [
                'players' => PlayerResource::collection($sidePlayers),
                'points' => $score->points ?? null,
                'result' => $score->result ?? null,
            ];
        };

        return [
            'id' => $this->id,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'duration_minutes' => $this->duration_minutes,
            'ends_at' => $this->duration_minutes && $this->started_at
                ? $this->started_at->copy()->addMinutes($this->duration_minutes)
                : null,
            'court' => new CourtResource($this->whenLoaded('court')),
            'players' => PlayerResource::collection($players->map->player),
            'team_a' => $team(0),
            'team_b' => $team(1),
            'skill_warning' => $this->skill_warning,
        ];
    }
}
