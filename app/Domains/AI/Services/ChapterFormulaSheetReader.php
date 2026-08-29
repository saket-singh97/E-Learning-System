<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Models\User;

/**
 * U5 — thin reader over the same already-extracted LessonArtifact.concepts
 * U2 reads, different rendering (design doc §4 decision 1). concepts only
 * carries {name, confidence, chunk_idxs} (confirmed from ExtractLessonConcepts,
 * S3) — no formula text or definition field exists to render a real formula
 * sheet from. Noting that gap here rather than silently expanding S3's own
 * AI-extraction contract (design doc B6 checklist): this renders a
 * per-chapter revision card of the distinct concept names taught, not a
 * worked formula sheet.
 *
 * S22 B13 review pass: originally read every lesson in the section
 * unconditionally, so this rendered the real mined content of paid,
 * not-yet-released, or otherwise ungated lessons to anyone who could see
 * the course was merely published — a materially bigger leak than the
 * syllabus tree it was modeled on (that page shows only author-written
 * titles). Now filters per lesson through the same isVisibleTo()+canStream()
 * pair every other content surface (DoubtChatService, both stream
 * controllers) already gates on, so a lesson this viewer isn't actually
 * allowed to stream contributes nothing to the card.
 */
class ChapterFormulaSheetReader
{
    /**
     * @return array<int, string>
     */
    public function forSection(CourseSection $section, LessonAccess $access, ?User $viewer): array
    {
        return $section->lessons
            ->filter(fn (Lesson $lesson) => $access->isVisibleTo($lesson, $viewer) && $access->canStream($lesson, $viewer))
            ->flatMap(fn (Lesson $lesson) => collect(LessonArtifact::latestReadyFor($lesson->id)?->concepts ?? [])->pluck('name'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
