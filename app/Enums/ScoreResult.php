<?php

namespace App\Enums;

enum ScoreResult: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Draw = 'draw';
}
