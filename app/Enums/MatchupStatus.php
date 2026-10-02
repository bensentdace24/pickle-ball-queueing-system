<?php

namespace App\Enums;

enum MatchupStatus: string
{
    case Pending = 'pending';
    case Started = 'started';
    case Cancelled = 'cancelled';
}
