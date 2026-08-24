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
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Design doc §4 decision 5 — the only new state a violation
            // trip adds; the terminal status itself reuses 'auto_submitted'
            // (same call AutoSubmitExpiredQuizAttempts already makes), so
            // this is what actually distinguishes "auto-submitted because
            // time ran out" from "auto-submitted because of a proctoring
            // violation" for an instructor reviewing it.
            $table->boolean('flagged_for_review')->default(false)->after('max_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('flagged_for_review');
        });
    }
};
