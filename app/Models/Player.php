<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'skill_level',
    ];

    public function queueEntries(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    public function activeQueueEntry()
    {
        return $this->queueEntries()
            ->whereIn('status', ['waiting', 'called', 'playing'])
            ->latest('joined_at')
            ->first();
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }
}
