<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankEvent extends Model
{
    protected $fillable = ['player_id', 'game_id', 'delta', 'points_after', 'reason'];
}
