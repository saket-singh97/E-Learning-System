<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Integrations\AiProvider;
use App\Models\User;
use Illuminate\Support\Collection;
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
     *
     * U4 (S22 B5) — $atSecond is optional and additive (design doc §4
     * decision 4): omitted, this method is byte-identical to before this
     * block; given, retrieval scopes to the chunks nearest that timestamp
     * instead of the whole-lesson semantic search, falling back to it only
     * if the lesson has no chunks at all yet.
     */
    public function ask(Lesson $lesson, User $viewer, string $question, ?int $atSecond = null): DoubtChatMessage
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

        $chunks = $atSecond !== null ? $this->nearbyChunks($artifact->id, $atSecond) : null;

        if ($chunks === null || $chunks->isEmpty()) {
            $chunks = LessonArtifactChunk::query()
                ->where('lesson_artifact_id', $artifact->id)
                ->nearestNeighbors('embedding', $queryVector, Distance::Cosine)
                ->limit(self::TOP_K)
                ->get();
        }

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

    /**
     * Sorted in PHP, not `orderByRaw` — a per-lesson chunk count is small
     * (one lecture's own segments), and CLAUDE.md keeps raw SQL out of
     * app/Domains/ entirely.
     *
     * @return Collection<int, LessonArtifactChunk>
     */
    private function nearbyChunks(int $artifactId, int $atSecond): Collection
    {
        return LessonArtifactChunk::query()
            ->where('lesson_artifact_id', $artifactId)
            ->get()
            ->sortBy(fn (LessonArtifactChunk $chunk): float => abs((($chunk->start_s + $chunk->end_s) / 2) - $atSecond))
            ->take(self::TOP_K)
            ->values();
    }
}
