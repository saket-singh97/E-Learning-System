<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * U2 — thin reader over already-extracted LessonArtifact.concepts +
 * LessonArtifactChunk.start_s (design doc §4 decision 1: no shared
 * "content deriver" abstraction with U1/U5, each renders the same source
 * data differently). A marker's timestamp is the earliest start_s among
 * the concept's own chunk_idxs — where that concept first comes up.
 */
class ChapterMarkerReader
{
    /**
     * @return array<int, array{label: string, start_s: float}>
     */
    public function forLesson(Lesson $lesson): array
    {
        $artifact = LessonArtifact::latestReadyFor($lesson->id);

        if ($artifact === null || empty($artifact->concepts)) {
            return [];
        }

        $startByChunkIdx = $artifact->chunkRows()->pluck('start_s', 'chunk_idx');

        return Collection::make($artifact->concepts)
            ->map(function (array $concept) use ($startByChunkIdx) {
                $startS = Collection::make($concept['chunk_idxs'] ?? [])
                    ->map(fn ($idx) => $startByChunkIdx[$idx] ?? null)
                    ->filter(fn ($start) => $start !== null)
                    ->min();

                return $startS === null ? null : ['label' => $concept['name'], 'start_s' => (float) $startS];
            })
            ->filter()
            ->sortBy('start_s')
            ->values()
            ->all();
    }
}
