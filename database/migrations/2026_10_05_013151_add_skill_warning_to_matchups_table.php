<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matchups', function (Blueprint $table) {
            $table->string('skill_warning')->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('matchups', function (Blueprint $table) {
            $table->dropColumn('skill_warning');
        });
    }
};
