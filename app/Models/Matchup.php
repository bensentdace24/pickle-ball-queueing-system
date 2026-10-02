<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matchup extends Model
{
    protected $fillable = ['match_size', 'duration_minutes', 'status', 'game_id'];

    public function matchupPlayers(): HasMany
    {
        return $this->hasMany(MatchupPlayer::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->oldest();
    }
}
