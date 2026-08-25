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
        Schema::create('omr_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Unique — one fixed template per quiz, regenerated in place
            // (not accumulated) if an admin reruns the generate action.
            // The offline test's own answer key lives on its Question
            // rows, reused not duplicated (design doc §3.2).
            $table->foreignId('quiz_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('question_count');
            $table->unsignedInteger('options_per_question');
            $table->unsignedInteger('roll_number_digits');
            // Bubble-grid coordinates, generated once at creation time from
            // the three counts above — never hand-authored (design doc §4
            // decision 8).
            $table->json('layout');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('omr_templates');
    }
};
