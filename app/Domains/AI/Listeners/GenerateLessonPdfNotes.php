<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\LessonPdfNoteGenerator;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * U1 — additive to LessonArtifactReady's existing listener list
 * (GenerateLessonSubtitles already listens here, S14 B2), not a new
 * trigger point (design doc §4 decision 3 applies the same shape to U1).
 */
class GenerateLessonPdfNotes implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(LessonArtifactReady $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $artifact = LessonArtifact::findOrFail($event->lessonArtifactId);
        $lesson = Lesson::findOrFail($artifact->lesson_id);

        app(LessonPdfNoteGenerator::class)->generate($lesson);
    }
}
