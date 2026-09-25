<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Queue extends Model
{
    use HasFactory;

    protected $table = 'queues';

    protected $fillable = [
        'player_id',
        'queue_number',
        'status',
        'joined_at',
        'called_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'called_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function gamePlayer(): HasOne
    {
        return $this->hasOne(GamePlayer::class);
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }

    public function scopeOrderedByJoinTime($query)
    {
        return $query->orderBy('joined_at')->orderBy('queue_number');
    }
}
