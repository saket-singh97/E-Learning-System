<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;

/**
 * U5 — thin reader over the same already-extracted LessonArtifact.concepts
 * U2 reads, different rendering (design doc §4 decision 1). concepts only
 * carries {name, confidence, chunk_idxs} (confirmed from ExtractLessonConcepts,
 * S3) — no formula text or definition field exists to render a real formula
 * sheet from. Noting that gap here rather than silently expanding S3's own
 * AI-extraction contract (design doc B6 checklist): this renders a
 * per-chapter revision card of the distinct concept names taught, not a
 * worked formula sheet.
 */
class ChapterFormulaSheetReader
{
    /**
     * @return array<int, string>
     */
    public function forSection(CourseSection $section): array
    {
        return $section->lessons
            ->flatMap(fn (Lesson $lesson) => collect(LessonArtifact::latestReadyFor($lesson->id)?->concepts ?? [])->pluck('name'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
