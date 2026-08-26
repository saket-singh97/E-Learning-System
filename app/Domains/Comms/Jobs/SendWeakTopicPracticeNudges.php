<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Batches\Models\Enrolment;
use App\Models\User;
use App\Notifications\WeakTopicPracticeNudgeDue;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * M15's "weak-topic-practice" nudge (S18 B7, design doc A4 pt.2) — a thin
 * notification wrapper around WeakTopicDetectionService::weakTopicsFor()
 * (S17 B7): a non-empty result for an actively-enrolled student's own
 * course triggers a nudge, an empty result is silently skipped, no new
 * detection logic of its own. The cross-domain service call (Comms -> AI)
 * is a deliberate, documented exception to CLAUDE.md's events-only rule
 * (design doc §4 decision 5) — a synchronous, on-demand read of an
 * existing detection query, not one domain reacting to another's state
 * change.
 *
 * Same dedupe/cadence/sweep shape as SendMissedClassReplayNudges/
 * SendInactivityNudges: notifications-table dedupe, once-per-week,
 * CurrentTenant cleared up front for the same unscoped cross-tenant sweep.
 */
class SendWeakTopicPracticeNudges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(WeakTopicDetectionService $service): void
    {
        app(CurrentTenant::class)->clear();

        Enrolment::where('status', 'active')
            ->with(['user', 'batch'])
            ->chunk(200, function (Collection $enrolments) use ($service): void {
                foreach ($enrolments as $enrolment) {
                    $this->maybeNudge($enrolment, $service);
                }
            });
    }

    protected function maybeNudge(Enrolment $enrolment, WeakTopicDetectionService $service): void
    {
        $student = $enrolment->user;
        $batch = $enrolment->batch;

        if ($student === null || $batch === null) {
            return;
        }

        if ($this->alreadyNudgedThisWeek($student)) {
            return;
        }

        $weakTopics = $service->weakTopicsFor($student, $batch->course_id);

        if ($weakTopics->isEmpty()) {
            return;
        }

        $student->notify(new WeakTopicPracticeNudgeDue($weakTopics));
    }

    protected function alreadyNudgedThisWeek(User $student): bool
    {
        return $student->notifications()
            ->where('type', WeakTopicPracticeNudgeDue::class)
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();
    }
}
