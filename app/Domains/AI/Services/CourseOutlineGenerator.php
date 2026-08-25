<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\CourseOutlineDraft;
use App\Integrations\AiProvider;
use App\Models\User;

/**
 * L9 — course outline generation from a topic prompt. Structure only
 * (section/lesson titles + one-line descriptions), never lesson bodies,
 * video, or quiz content (S17 design §4 decision 6) — those already have
 * dedicated pipelines (S1-S3 ingestion, L5's question generation). No
 * spend-cap handling here: AiProvider::chat() already goes through
 * SpendCapGuardedAiDriver at the container-binding level, same as every
 * other call site (DoubtChatService, QuestionGenerator) — an
 * AiSpendCapExceededException simply bubbles up uncaught.
 */
class CourseOutlineGenerator
{
    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    public function generate(string $topic, ?string $targetLevel, User $creator): CourseOutlineDraft
    {
        $result = $this->ai->chat(
            messages: [['role' => 'user', 'content' => $this->promptFor($topic, $targetLevel)]],
            responseSchema: ['outline'],
        );

        return CourseOutlineDraft::create([
            'created_by_user_id' => $creator->id,
            'topic' => $topic,
            'target_level' => $targetLevel,
            'status' => 'draft',
            'generated_outline' => $result->structured['outline'] ?? [],
            'token_cost' => $result->usage,
        ]);
    }

    protected function promptFor(string $topic, ?string $targetLevel): string
    {
        $level = $targetLevel !== null ? " for {$targetLevel} level students" : '';

        return "Generate a course outline for \"{$topic}\"{$level}. Return an array of ".
            'sections, each with a section_title and a lessons array of {title, description}.';
    }
}
