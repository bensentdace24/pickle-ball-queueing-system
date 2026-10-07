<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->uuid('qr_token')->unique()->nullable()->after('id');
        });

        // Backfill tokens for any players that already existed.
        DB::table('players')->whereNull('qr_token')->orderBy('id')->each(function ($player) {
            DB::table('players')->where('id', $player->id)->update(['qr_token' => (string) \Illuminate\Support\Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('qr_token');
        });
    }
};
