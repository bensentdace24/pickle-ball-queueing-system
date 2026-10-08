<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class MatchupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $players = $this->relationLoaded('matchupPlayers') ? $this->matchupPlayers : collect();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'match_size' => $this->match_size,
            'duration_minutes' => $this->duration_minutes,
            'created_at' => $this->created_at,
            'team_a' => PlayerResource::collection($players->where('side', 0)->map->player->values()),
            'team_b' => PlayerResource::collection($players->where('side', 1)->map->player->values()),
            'skill_warning' => $this->skill_warning,
            'estimated_minutes' => $this->getAttribute('estimated_minutes'),
        ];
    }
}
