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
        Schema::table('quizzes', function (Blueprint $table) {
            // Opt-in per quiz, default false — same posture Lesson's own
            // print_disabled (S14) took for a similar browser-deterrent
            // toggle. Zero blast radius on every existing quiz.
            $table->boolean('proctoring_enabled')->default(false)->after('max_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('proctoring_enabled');
        });
    }
};
