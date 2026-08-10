<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Integrations\AiProvider;
use App\Models\User;
use Pgvector\Laravel\Distance;

class DoubtChatService
{
    private const TOP_K = 5;

    private const STILL_PROCESSING_ANSWER = "This lesson's AI content is still processing — check back in a few minutes.";

    public function __construct(
        private readonly LessonAccess $access,
        private readonly AiProvider $ai,
    ) {}

    /**
     * S1 §5.2 consumer #2 ("doubt-chat RAG"). Gates through the same single
     * LessonAccess class streaming and the heartbeat use — per CLAUDE.md,
     * no inline visibility check invented here.
     */
    public function ask(Lesson $lesson, User $viewer, string $question): DoubtChatMessage
    {
        abort_unless($this->access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($this->access->canStream($lesson, $viewer), 403);

        $artifact = LessonArtifact::latestReadyFor($lesson->id);

        if (! $artifact) {
            return DoubtChatMessage::create([
                'lesson_id' => $lesson->id,
                'user_id' => $viewer->id,
                'question' => $question,
                'answer' => self::STILL_PROCESSING_ANSWER,
                'cited_chunk_idxs' => [],
            ]);
        }

        $queryVector = $this->ai->embed($question);

        $chunks = LessonArtifactChunk::query()
            ->where('lesson_artifact_id', $artifact->id)
            ->nearestNeighbors('embedding', $queryVector, Distance::Cosine)
            ->limit(self::TOP_K)
            ->get();

        $context = $chunks
            ->map(fn (LessonArtifactChunk $chunk): string => "[{$chunk->start_s}s-{$chunk->end_s}s] {$chunk->text}")
            ->implode("\n");

        $result = $this->ai->chat([
            ['role' => 'system', 'content' => 'Answer the student using only the lecture excerpts below. Be concise.'],
            ['role' => 'user', 'content' => "Lecture excerpts:\n{$context}\n\nQuestion: {$question}"],
        ]);

        return DoubtChatMessage::create([
            'lesson_id' => $lesson->id,
            'user_id' => $viewer->id,
            'question' => $question,
            'answer' => $result->text,
            'cited_chunk_idxs' => $chunks->pluck('chunk_idx')->all(),
            // The embed() call just above has no cost field in AiProvider's
            // contract at all, so only the chat() completion's usage is
            // capturable here — still closes most of the gap TokenCostLedger
            // previously had for doubt chat (see its own docblock).
            'token_cost' => $result->usage,
        ]);
    }
}
