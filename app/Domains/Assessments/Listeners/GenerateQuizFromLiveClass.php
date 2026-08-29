<?php

namespace App\Domains\Assessments\Listeners;

use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\QuestionGenerator;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * U6 — additive to LessonArtifactReady's existing listener list (design doc
 * §4 decision 3), same shape as GenerateLessonSubtitles/GenerateLessonPdfNotes.
 * LessonVideoReady/StartIngestion fires for every lesson type, not just
 * live classes, so the type guard lives here (design doc B2 checklist).
 * Draft only (Quiz::STATUSES default, same as a manually-created quiz's own
 * form default) — the underlying Questions are also 'draft'/'ai_generated'
 * from fromSeedQuestions(), so this still needs the same S6 review-queue
 * pass before either is student-visible.
 */
class GenerateQuizFromLiveClass implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(LessonArtifactReady $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $artifact = LessonArtifact::findOrFail($event->lessonArtifactId);
        $lesson = Lesson::findOrFail($artifact->lesson_id);

        if ($lesson->type !== 'live_session') {
            return;
        }

        $questions = app(QuestionGenerator::class)->fromSeedQuestions($lesson);

        if ($questions->isEmpty()) {
            return;
        }

        // S22 B13 review pass: a retried ingestion (EditLesson's own
        // "Retry ingestion" action) re-fires LessonArtifactReady for the
        // same lesson — firstOrCreate keeps this a no-op the second time,
        // same guard GenerateLessonPdfNotes' own updateOrCreate() already
        // has on this exact event.
        Quiz::firstOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'title' => "{$lesson->title} — Quiz",
                'blueprint' => [['count' => $questions->count()]],
                'time_limit_minutes' => 30,
                'max_attempts' => 1,
                'status' => 'draft',
            ],
        );
    }
}
