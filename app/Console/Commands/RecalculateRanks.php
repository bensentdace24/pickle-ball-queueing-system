<?php

namespace App\Console\Commands;

use App\Services\RankService;
use Illuminate\Console\Command;

class RecalculateRanks extends Command
{
    protected $signature = 'ranks:recalculate';

    protected $description = 'Rebuild every player\'s rank points from finished games';

    public function handle(RankService $ranks): int
    {
        $count = $ranks->recalculateAll();
        $this->info("Recalculated from {$count} finished games.");

        return self::SUCCESS;
    }
}
