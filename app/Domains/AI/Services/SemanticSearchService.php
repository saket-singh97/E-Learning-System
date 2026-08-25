<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Services\LessonAccess;
use App\Integrations\AiProvider;
use App\Models\User;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Distance;

/**
 * L12 — semantic search across already-ingested lesson video/audio
 * transcripts (`lesson_artifact_chunks`) only (S17 design §2.2). Reuses
 * DoubtChatService's own RAG retrieval pattern (embed the query, then
 * `nearestNeighbors()`), scoped wider than one lesson — across every
 * lesson in the tenant instead of one artifact.
 *
 * Every candidate chunk is filtered through `LessonAccess::isVisibleTo()`
 * before it reaches the caller (design §4 decision 5) — a bare
 * `nearestNeighbors()` query has no concept of enrollment or course-publish
 * state and would otherwise leak chunk text across courses the viewer
 * never enrolled in, the same gap the stream route/player/heartbeat/
 * doubt-chat all already guard against.
 */
class SemanticSearchService
{
    /**
     * Over-fetched candidate pool, filtered down to RESULT_LIMIT after the
     * visibility check runs — limiting to RESULT_LIMIT *before* filtering
     * could return fewer than RESULT_LIMIT (or zero) visible results even
     * when plenty exist, whenever the top raw matches happen to be from
     * lessons the viewer can't see.
     */
    private const CANDIDATE_POOL = 50;

    private const RESULT_LIMIT = 10;

    public function __construct(
        private readonly AiProvider $ai,
        private readonly LessonAccess $access,
    ) {}

    /**
     * @return Collection<int, LessonArtifactChunk>
     */
    public function search(string $query, User $viewer, int $tenantId): Collection
    {
        if (trim($query) === '') {
            return collect();
        }

        $queryVector = $this->ai->embed($query);

        $candidates = LessonArtifactChunk::query()
            ->where('tenant_id', $tenantId)
            // Same "only ever read a ready artifact's content" contract
            // DoubtChatService::ask() already enforces via
            // latestReadyFor() — a still-processing or failed transcript's
            // chunks are never search-worthy content.
            ->whereHas('lessonArtifact', fn ($artifactQuery) => $artifactQuery->where('status', 'ready'))
            ->with('lessonArtifact.lesson.section.course')
            ->nearestNeighbors('embedding', $queryVector, Distance::Cosine)
            ->limit(self::CANDIDATE_POOL)
            ->get();

        return $candidates
            ->filter(function (LessonArtifactChunk $chunk) use ($viewer): bool {
                $lesson = $chunk->lessonArtifact?->lesson;

                return $lesson !== null && $this->access->isVisibleTo($lesson, $viewer);
            })
            ->take(self::RESULT_LIMIT)
            ->values();
    }
}
