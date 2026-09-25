<?php

use App\Enums\QueueStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS queue_number_seq START 1');

        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('queue_number')->unique();
            $table->string('status')->default(QueueStatus::Waiting->value);
            $table->timestamp('joined_at');
            $table->timestamp('called_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE queues ALTER COLUMN queue_number SET DEFAULT nextval('queue_number_seq')");
        DB::statement('ALTER SEQUENCE queue_number_seq OWNED BY queues.queue_number');

        DB::statement(
            "CREATE UNIQUE INDEX queues_one_active_per_player
             ON queues (player_id)
             WHERE status IN ('waiting', 'called', 'playing')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
