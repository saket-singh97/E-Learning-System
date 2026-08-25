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
        Schema::create('omr_sheet_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('omr_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('image_disk_path');
            $table->string('status')->default('pending');
            $table->string('detected_roll_number')->nullable();
            // Nullable — a roll number that doesn't resolve to a real
            // enrolment queues status='failed' for manual admin
            // resolution rather than being silently dropped (design doc
            // §4 decision 9), so this stays null until a real match lands.
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nullable, set once graded (B15) — an OMR-graded offline test
            // appears in the exact same QuizAttempt every online attempt
            // already does, no parallel "offline score" concept.
            $table->foreignId('quiz_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->json('raw_bubble_results')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('omr_sheet_scans');
    }
};
