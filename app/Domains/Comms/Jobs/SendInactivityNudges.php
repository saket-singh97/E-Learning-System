<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Models\User;
use App\Notifications\InactivityNudgeDue;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * M15's "3-day inactivity" nudge (S9 B13) — this app has no single
 * "last active" column anywhere (confirmed by reading User's own schema
 * before assuming one existed, per the plan note's own instruction);
 * "last activity" is derived per student from the two real signals that
 * do exist, VideoWatchEvent.created_at and QuizAttempt.created_at, taking
 * the more recent of the two.
 *
 * A real queued job scheduled via Schedule::job() on the 'scheduled'
 * Horizon queue (S9 B10's own posture) — CurrentTenant cleared up front
 * for the same persistent-worker-leak reason every B10-converted sweep
 * documents, since this scans every tenant's active students in one pass.
 *
 * Once-per-week dedupe against the notifications table, same judgment
 * call SendLowAttendanceAlert makes for the same "not on every check"
 * requirement — a persistently-inactive student would otherwise get a
 * fresh nudge every single day this job runs.
 */
class SendInactivityNudges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INACTIVITY_DAYS = 3;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        $this->activelyEnrolledStudents()
            ->chunk(200, function ($students): void {
                foreach ($students as $student) {
                    $this->maybeNudge($student);
                }
            });
    }

    protected function activelyEnrolledStudents(): Builder
    {
        return User::role('student')
            ->whereIn('id', Enrolment::where('status', 'active')
                // A student who enrolled yesterday hasn't had 3 days to be
                // "inactive" yet — gating on the enrolment's own age avoids
                // nudging someone for not having done anything they never
                // had the chance to do.
                ->where('requested_at', '<=', now()->subDays(self::INACTIVITY_DAYS))
                ->pluck('user_id'));
    }

    protected function maybeNudge(User $student): void
    {
        $lastActiveAt = $this->lastActivityAt($student);

        if ($lastActiveAt !== null && $lastActiveAt->gt(now()->subDays(self::INACTIVITY_DAYS))) {
            return;
        }

        if ($this->alreadyNudgedThisWeek($student)) {
            return;
        }

        $student->notify(new InactivityNudgeDue($lastActiveAt));
    }

    protected function lastActivityAt(User $student): ?CarbonInterface
    {
        $lastWatch = VideoWatchEvent::where('user_id', $student->id)->max('created_at');
        $lastQuiz = QuizAttempt::where('user_id', $student->id)->max('created_at');

        return collect([$lastWatch, $lastQuiz])
            ->filter()
            ->map(fn (string $date): Carbon => Carbon::parse($date))
            ->sort()
            ->last();
    }

    protected function alreadyNudgedThisWeek(User $student): bool
    {
        return $student->notifications()
            ->where('type', InactivityNudgeDue::class)
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();
    }
}
