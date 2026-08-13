<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S9 B14 review pass — QuestionGenerator::generate() makes a real
 * AiProvider::chat() call per requested question (one of the six real
 * AI-calling sites B12's spend cap decorator guards) but discarded the
 * returned usage/cost entirely; TokenCostLedger never had anywhere to
 * read it back from. Same jsonb {in, out, usd} shape as
 * lesson_artifacts.token_cost / doubt_chat_messages.token_cost. Null on a
 * Question promoted from fromSeedQuestions() — that path makes no AI
 * call at all, same reasoning doubt_chat_messages.token_cost stays null
 * on its own "still processing" placeholder row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->jsonb('token_cost')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('token_cost');
        });
    }
};
