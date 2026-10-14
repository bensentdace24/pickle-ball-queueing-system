<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rank_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->unsignedInteger('points_after');
            $table->string('reason')->default('game'); // game | adjustment (later: old-record bonus)
            $table->timestamps();

            // A game can only award a player once, even if "finish" is retried
            $table->unique(['player_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_events');
    }
};
