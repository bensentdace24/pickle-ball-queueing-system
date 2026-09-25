<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['game_id', 'player_id']);
            $table->unique(['game_id', 'queue_id']);
        });

        // Note: "a player can't be in two different active games at once" spans
        // the games + game_players tables, so it can't be a plain DB constraint —
        // we'll enforce that in the service layer inside a locked transaction.
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
