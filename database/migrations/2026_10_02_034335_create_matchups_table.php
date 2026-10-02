<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchups', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('match_size'); // 2 or 4
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status')->default('pending'); // pending | started | cancelled
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchups');
    }
};
