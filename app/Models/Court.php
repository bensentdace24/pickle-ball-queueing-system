<?php

namespace App\Models;

use App\Enums\CourtStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Court extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
    ];

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function activeGame(): HasOne
    {
        return $this->hasOne(Game::class)->where('status', 'playing');
    }

    public function isAvailable(): bool
    {
        return $this->status === CourtStatus::Available->value;
    }
}
