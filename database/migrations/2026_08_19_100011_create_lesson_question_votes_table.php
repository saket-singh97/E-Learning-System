<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique on (lesson_question_id, user_id) — one upvote per user per
     * question enforced at the DB level, not an app-level check alone
     * (design doc decision 8, same defense-in-depth AssignmentSubmission's
     * own idempotency key already models).
     */
    public function up(): void
    {
        Schema::create('lesson_question_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lesson_question_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_question_votes');
    }
};
