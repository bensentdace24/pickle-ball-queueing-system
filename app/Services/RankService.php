<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\RankEvent;
use Illuminate\Support\Facades\DB;

class RankService
{
    public function tiers(): array
    {
        return config('ranking.tiers');
    }

    public function tierIndex(int $points): int
    {
        $index = 0;

        foreach ($this->tiers() as $i => $tier) {
            if ($points >= $tier['min']) {
                $index = $i;
            }
        }

        return $index;
    }

    public function summary(int $points): array
    {
        $tiers = $this->tiers();
        $i = $this->tierIndex($points);
        $next = $tiers[$i + 1] ?? null;
        $floor = $tiers[$i]['min'];

        return [
            'points' => $points,
            'tier' => $tiers[$i]['name'],
            'tier_index' => $i,
            'next_tier' => $next['name'] ?? null,
            'points_to_next' => $next ? $next['min'] - $points : null,
            'progress_percent' => $next ? (int) floor((($points - $floor) / ($next['min'] - $floor)) * 100) : 100,
        ];
    }

    /**
     * Awards rank points for a finished game. Safe to call twice: a player
     * is only ever awarded once per game.
     */
    public function applyGame(Game $game): void
    {
        $game->loadMissing('gamePlayers', 'scores');

        if ($game->status !== 'completed') {
            return;
        }

        $minSeconds = (int) config('ranking.min_game_seconds');
        if (
            $minSeconds > 0 && $game->started_at && $game->completed_at
            && ($game->completed_at->getTimestamp() - $game->started_at->getTimestamp()) < $minSeconds
        ) {
            return;
        }

        $scores = $game->scores->keyBy('side');
        if (! $scores->has(0) || ! $scores->has(1)) {
            return;
        }

        $players = Player::whereIn('id', $game->gamePlayers->pluck('player_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Work out both teams' average tier BEFORE anyone's points change
        $teamIndex = [];
        foreach ([0, 1] as $side) {
            $ids = $game->gamePlayers->where('side', $side)->pluck('player_id');
            $teamIndex[$side] = $ids->isEmpty()
                ? 0
                : $ids->avg(fn($id) => $this->tierIndex((int) $players[$id]->rank_points));
        }

        $step = (int) config('ranking.gap_step');
        $max = (int) config('ranking.gap_max');
        $margin = min(11, abs($scores[0]->points - $scores[1]->points));

        foreach ([0, 1] as $side) {
            $gap = max(-$max, min($max, $teamIndex[1 - $side] - $teamIndex[$side]));
            $bonus = (int) round($gap * $step);

            $delta = match ($scores[$side]->result) {
                'win' => (int) config('ranking.win') + $margin * (int) config('ranking.win_margin') + $bonus,
                'loss' => -max(
                    (int) config('ranking.min_loss'),
                    (int) config('ranking.loss') + $margin * (int) config('ranking.loss_margin') - $bonus
                ),
                default => (int) config('ranking.draw'),
            };

            foreach ($game->gamePlayers->where('side', $side) as $gamePlayer) {
                $player = $players[$gamePlayer->player_id];

                $already = RankEvent::where('player_id', $player->id)->where('game_id', $game->id)->exists();
                if ($already) {
                    continue;
                }

                $before = (int) $player->rank_points;
                $after = max(0, $before + $delta);

                RankEvent::create([
                    'player_id' => $player->id,
                    'game_id' => $game->id,
                    'delta' => $after - $before,
                    'points_after' => $after,
                    'reason' => 'game',
                ]);

                $player->update(['rank_points' => $after]);
            }
        }
    }

    /**
     * Rebuilds everyone's points from finished games, in the order they were
     * played. Use after changing config/ranking.php.
     */
    public function recalculateAll(): int
    {
        return DB::transaction(function () {
            RankEvent::where('reason', 'game')->delete();

            // Start from any manual adjustments (such as a future old-record bonus)
            DB::statement(
                'UPDATE players SET rank_points = COALESCE((SELECT GREATEST(0, SUM(delta)) FROM rank_events WHERE rank_events.player_id = players.id), 0)'
            );

            $games = Game::with('gamePlayers', 'scores')
                ->where('status', 'completed')
                ->orderBy('completed_at')
                ->orderBy('id')
                ->get();

            foreach ($games as $game) {
                $this->applyGame($game);
            }

            return $games->count();
        });
    }
}
