<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchupPlayer extends Model
{
    protected $fillable = ['matchup_id', 'queue_id', 'player_id', 'side'];

    public function matchup(): BelongsTo
    {
        return $this->belongsTo(Matchup::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id');
    }
}
