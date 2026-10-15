<?php

return [
    'tiers' => [
        ['name' => 'Rookie', 'min' => 0],
        ['name' => 'Warrior', 'min' => 100],
        ['name' => 'Elite', 'min' => 250],
        ['name' => 'Master', 'min' => 450],
        ['name' => 'Grandmaster', 'min' => 700],
        ['name' => 'Epic', 'min' => 1000],
        ['name' => 'Legend', 'min' => 1400],
        ['name' => 'Mythic', 'min' => 1900],
    ],

    'win' => 25,
    'loss' => 15,
    'draw' => 5,

    // Opponent-tier adjustment: +/- gap_step per tier of difference, up to gap_max tiers
    'gap_step' => 5,
    'gap_max' => 2,
    'min_loss' => 5,

    // Games shorter than this don't count. Keep 0 while testing and raise it
    // (for example 180) at go-live so staff can't farm points with instant fake scores.
    'min_game_seconds' => 0,

    // Smart matching: how many games before rank points fully replace the skill-label estimate
    'trust_after_games' => 1,

    // Singles: warn staff when two players are further apart than this many rank points
    'singles_max_gap' => 300,
];
