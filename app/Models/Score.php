<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    protected $fillable = ['game_id', 'side', 'points', 'result'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
