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
        Schema::create('doubt_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->jsonb('cited_chunk_idxs');
            // Null on the "still processing" placeholder answer (no AI call
            // made) and on any row from before this column existed; populated
            // from the chat() call's usage otherwise. Without this, every
            // doubt-chat question — the highest-volume, most user-driven AI
            // cost in the app, unlike the one-time ingestion chat call — was
            // invisible to TokenCostLedger/"AI spend today".
            $table->jsonb('token_cost')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doubt_chat_messages');
    }
};
