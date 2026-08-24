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
        Schema::table('quiz_answers', function (Blueprint $table) {
            // Nullable, no default — a question the student never visited
            // stays null, not 0 (design doc §3.2). Accumulated across
            // repeated autosaves, never overwritten.
            $table->unsignedInteger('time_spent_seconds')->nullable()->after('response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('time_spent_seconds');
        });
    }
};
