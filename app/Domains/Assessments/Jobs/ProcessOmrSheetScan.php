<?php

namespace App\Domains\Assessments\Jobs;

use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\OmrScanner;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * S16 B15 (I11 pt.2) — everything after `OmrScanner::scan()`'s own pure
 * image reading: roll-number-to-`Enrolment` resolution (design doc §4
 * decision 9) and grading. A non-matching roll number queues `failed`
 * for manual admin resolution, never silently dropped. On a match, a
 * real `QuizAttempt` is created and handed to
 * `QuizAttemptService::submit()` — the exact same per-type grading every
 * online attempt already uses, so an OMR-graded offline test appears in
 * the same Gradebook/reports, no parallel "offline score" concept.
 *
 * `CurrentTenant` is explicitly (re)bound to this scan's own tenant, same
 * per-row-tenant-binding shape `ZoomRecordingWebhookHandler` already
 * established — this can run on a queue worker with no tenant bound, or
 * a stale one left over from whatever job ran immediately before it.
 */
class ProcessOmrSheetScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $scanId) {}

    public function handle(OmrScanner $scanner, QuizAttemptService $quizAttemptService): void
    {
        $scan = OmrSheetScan::withoutGlobalScope('tenant')->findOrFail($this->scanId);
        app(CurrentTenant::class)->set(Tenant::findOrFail($scan->tenant_id));

        $template = $scan->template;
        $quiz = $template->quiz;

        $result = $scanner->scan($scan);
        $rollNumber = $result['detected_roll_number'];
        $responses = $result['responses'];

        $courseId = $quiz->lesson?->section?->course_id;

        $enrolment = ctype_digit($rollNumber) && $courseId !== null
            ? Enrolment::where('id', (int) $rollNumber)
                ->whereHas('batch', fn ($query) => $query->where('course_id', $courseId))
                ->first()
            : null;

        if ($enrolment === null) {
            $scan->update([
                'status' => 'failed',
                'detected_roll_number' => $rollNumber,
                'raw_bubble_results' => $responses,
            ]);

            return;
        }

        $questionIds = $template->question_ids ?? [];

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $enrolment->user_id,
            'question_ids' => $questionIds,
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now(),
            'max_score' => count($questionIds),
        ]);

        $quizAttemptService->submit($attempt, $responses, 'submitted');

        $scan->update([
            'status' => 'processed',
            'detected_roll_number' => $rollNumber,
            'matched_user_id' => $enrolment->user_id,
            'quiz_attempt_id' => $attempt->id,
            'raw_bubble_results' => $responses,
        ]);
    }
}
