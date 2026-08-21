<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique on (live_poll_id, user_id) — one response per student per
     * poll enforced at the DB level, not an app-level check alone (design
     * doc decision 11, same defense-in-depth precedent S13's own
     * lesson_question_votes already used).
     */
    public function up(): void
    {
        Schema::create('live_poll_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['live_poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_poll_responses');
    }
};
