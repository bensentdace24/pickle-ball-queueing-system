<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // queue_number can no longer be required at insert time — pending
        // entries don't get one until approved.
        DB::statement('ALTER TABLE queues ALTER COLUMN queue_number DROP NOT NULL');

        Schema::table('queues', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('called_at');
        });

        // Pending entries now also count toward "one active entry per player".
        DB::statement('DROP INDEX IF EXISTS queues_one_active_per_player');
        DB::statement(
            "CREATE UNIQUE INDEX queues_one_active_per_player
             ON queues (player_id)
             WHERE status IN ('pending', 'waiting', 'called', 'playing')"
        );
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });

        DB::statement('DROP INDEX IF EXISTS queues_one_active_per_player');
        DB::statement(
            "CREATE UNIQUE INDEX queues_one_active_per_player
             ON queues (player_id)
             WHERE status IN ('waiting', 'called', 'playing')"
        );
    }
};
