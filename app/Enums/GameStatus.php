<?php

namespace App\Enums;

enum GameStatus: string
{
    case Playing = 'playing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }
}
