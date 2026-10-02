<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('side'); // 0 = Team A, 1 = Team B
            $table->unsignedInteger('points');
            $table->string('result'); // win | loss | draw
            $table->timestamps();

            $table->unique(['game_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
