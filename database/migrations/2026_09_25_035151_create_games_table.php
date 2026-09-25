<?php

use App\Enums\GameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default(GameStatus::Playing->value);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // A court can only have ONE active game at a time.
        DB::statement(
            "CREATE UNIQUE INDEX games_one_active_per_court
             ON games (court_id)
             WHERE status = 'playing'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
