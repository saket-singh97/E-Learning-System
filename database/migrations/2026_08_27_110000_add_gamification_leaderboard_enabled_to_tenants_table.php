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
        Schema::table('tenants', function (Blueprint $table) {
            // Design doc §4 decision 9 — opt-out hides peer ranking only;
            // XP/badges stay visible on the student's own profile regardless.
            $table->boolean('gamification_leaderboard_enabled')->default(true)->after('plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('gamification_leaderboard_enabled');
        });
    }
};
