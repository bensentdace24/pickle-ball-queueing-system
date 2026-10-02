<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchup_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matchup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('side'); // 0 = Team A, 1 = Team B
            $table->timestamps();

            $table->unique(['matchup_id', 'queue_id']);
            $table->unique(['matchup_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchup_players');
    }
};
