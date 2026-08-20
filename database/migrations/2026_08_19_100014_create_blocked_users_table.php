<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One directed row (blocker -> blocked) — the send-path checks both
     * directions rather than writing two rows per block action (design doc
     * decision 9): one row, one source of truth for who did the blocking,
     * still fully bidirectional in effect.
     */
    public function up(): void
    {
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'blocked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
    }
};
