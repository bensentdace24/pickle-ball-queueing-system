<?php

namespace App\Enums;

enum CourtStatus: string
{
    case Available = 'available';
    case Playing = 'playing';
    case Maintenance = 'maintenance';

    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }
}
