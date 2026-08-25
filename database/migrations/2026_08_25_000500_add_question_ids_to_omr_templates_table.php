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
        Schema::table('omr_templates', function (Blueprint $table) {
            // The exact, fixed question set drawn once at template-
            // generation time (same blueprint draw QuizAttemptService's
            // own start() already uses) — the physical sheet is printed
            // against this fixed list, so a later scan must grade against
            // the same list, not a fresh random draw.
            $table->json('question_ids')->nullable()->after('roll_number_digits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('omr_templates', function (Blueprint $table) {
            $table->dropColumn('question_ids');
        });
    }
};
