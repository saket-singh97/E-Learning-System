<?php

namespace App\Domains\Comms\Services;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Fees\Models\FeeInvoice;
use App\Models\User;
use App\Support\Caching\AggregateCache;
use Illuminate\Support\Collection;

/**
 * The one place that answers "how is this student doing?" — attendance,
 * video coverage, quiz average, fee status — read directly from each
 * domain's own models (CLAUDE.md's established cross-domain model-read
 * rule), never by calling another domain's service or job class. The
 * video-coverage merge-interval math duplicates
 * Batches\Jobs\RecordVideoCoverageAttendance::mergedCoveredSeconds()
 * rather than calling it, for the same reason Quiz::isVisibleTo() reads
 * Lesson/Course directly instead of calling LessonAccess (S6 B5, see
 * HANDOVER's "Avoid" list): a Job class is not a model, so calling into it
 * would be a cross-domain service call, not a read.
 *
 * Built for the parent portal (S8 B2); the design doc's own B5 note
 * expects the staff dashboard to show these same four numbers, so this
 * lives here rather than inline in ParentPortal so B5 can reuse it.
 */
class StudentMetrics
{
    /**
     * "At-risk" has no existing definition anywhere in this codebase or its
     * docs — 60% was InstituteOverviewWidget's own judgment call (S8 B5),
     * moved here in S9 B13 once a second consumer (SendLowAttendanceAlert)
     * needed the exact same threshold: this service is the natural home
     * for "how is this student doing," and "is this student at risk" is a
     * direct derivative of the numbers it already computes — a Filament
     * widget class is not, so duplicating the threshold there instead
     * would have been the wrong direction for two consumers to share it.
     */
    public const AT_RISK_THRESHOLD = 60.0;

    public function __construct(private readonly AggregateCache $cache) {}

    /**
     * S12 B12 (design doc §4 decision 11) — only the first 4 fields are
     * cached; `outstanding_invoices` is deliberately excluded and always
     * fetched fresh. Two reasons, not one: decision 11 itself scopes the
     * cache layer to "computed aggregates... not raw model reads," and a
     * list of live `FeeInvoice` rows returned verbatim is exactly the raw
     * read that line rules out — but even setting that wording aside,
     * invoice status changes via payment webhooks/`FlagOverdueInvoices`,
     * neither of which is in this block's own bust list
     * (`AssignmentSubmission` graded / `QuizAttempt` submitted /
     * `AttendanceRecord` created-updated), so caching it would go stale
     * with no invalidation path at all, not just a `TTL`-bounded one.
     * `fee_status` has the identical staleness exposure but is a plain
     * derived string, not a raw row list, so it's cached anyway and
     * leans on the TTL safety net for that one gap (`AggregateCache`'s
     * own docblock).
     *
     * @return array{
     *     attendance_percent: ?float,
     *     coverage_percent: ?float,
     *     quiz_average_percent: ?float,
     *     fee_status: string,
     *     outstanding_invoices: Collection<int, FeeInvoice>,
     * }
     */
    public function summarize(User $student): array
    {
        $cached = $this->cache->rememberStudentMetrics(
            $student->tenant_id,
            $student->id,
            fn (): array => [
                'attendance_percent' => $this->attendancePercent($student),
                'coverage_percent' => $this->coveragePercent($student),
                'quiz_average_percent' => $this->quizAveragePercent($student),
                'fee_status' => $this->feeStatus($student),
            ],
        );

        return $cached + [
            'outstanding_invoices' => FeeInvoice::where('user_id', $student->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->orderBy('due_date')
                ->get(),
        ];
    }

    /**
     * Only BatchSession-sourced rows carry a true present/absent
     * denominator: the video_coverage source only ever writes a
     * 'present' row once the ≥80% threshold is crossed, never an
     * 'absent' one, so mixing it in would inflate the numerator with no
     * matching denominator. BatchSession rows are written for every
     * enrolled student either way (present or absent) by the teacher's
     * bulk-mark action, so they're the only reliable denominator — a
     * judgment call, not something pinned anywhere else in the app.
     */
    protected function attendancePercent(User $student): ?float
    {
        $records = AttendanceRecord::where('user_id', $student->id)
            ->where('attendable_type', BatchSession::class)
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        return round($records->where('status', 'present')->count() / $records->count() * 100, 1);
    }

    /**
     * Per lesson: merge this student's watched [start_s, end_s] intervals
     * and divide by the lesson's video_duration_seconds — the exact
     * formula RecordVideoCoverageAttendance uses to decide presence,
     * duplicated rather than called (see class docblock). The portal's
     * "coverage average" is the mean of each watched lesson's own ratio,
     * not seconds-summed-over-durations-summed, so one long lecture
     * doesn't drown out several short, fully-watched ones.
     */
    protected function coveragePercent(User $student): ?float
    {
        $lessonIds = VideoWatchEvent::where('user_id', $student->id)->distinct()->pluck('lesson_id');

        if ($lessonIds->isEmpty()) {
            return null;
        }

        $ratios = $lessonIds
            ->map(function (int $lessonId) use ($student): ?float {
                $lesson = Lesson::find($lessonId);

                if ($lesson === null || blank($lesson->video_duration_seconds) || $lesson->video_duration_seconds <= 0) {
                    return null;
                }

                $events = VideoWatchEvent::where('lesson_id', $lessonId)->where('user_id', $student->id)->get();

                return min(1.0, $this->mergedCoveredSeconds($events) / $lesson->video_duration_seconds);
            })
            ->filter(fn (?float $ratio) => $ratio !== null);

        if ($ratios->isEmpty()) {
            return null;
        }

        return round($ratios->avg() * 100, 1);
    }

    /**
     * @param  Collection<int, VideoWatchEvent>  $events
     */
    protected function mergedCoveredSeconds(Collection $events): int
    {
        $intervals = $events
            ->map(fn (VideoWatchEvent $event): array => [$event->start_s, $event->end_s])
            ->sort(fn (array $a, array $b): int => $a[0] <=> $b[0])
            ->values();

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return array_sum(array_map(fn (array $interval): int => $interval[1] - $interval[0], $merged));
    }

    /**
     * Average of each completed attempt's own score/max_score ratio, not
     * sum(score)/sum(max_score) — a 5-question quiz and a 50-question
     * quiz each count as one data point, matching how QuizRunner already
     * shows every attempt as its own fraction rather than pooling raw
     * points across quizzes of different sizes. Attempts still
     * 'in_progress' or with a null score (pending short-answer review)
     * are excluded — there's nothing to average yet.
     */
    protected function quizAveragePercent(User $student): ?float
    {
        $attempts = QuizAttempt::where('user_id', $student->id)
            ->where('status', '!=', 'in_progress')
            ->whereNotNull('score')
            ->get();

        $percentages = $attempts
            ->filter(fn (QuizAttempt $attempt) => (float) $attempt->max_score > 0)
            ->map(fn (QuizAttempt $attempt) => (float) $attempt->score / (float) $attempt->max_score * 100);

        if ($percentages->isEmpty()) {
            return null;
        }

        return round($percentages->avg(), 1);
    }

    /**
     * No existing "good standing" flag anywhere to reuse (confirmed by
     * reading FlagOverdueInvoices/SendFeeReminders — both filter raw
     * invoice rows, never roll one up per student). Worst-status-wins:
     * any overdue invoice makes the summary 'overdue' even if others are
     * paid.
     */
    protected function feeStatus(User $student): string
    {
        $statuses = FeeInvoice::where('user_id', $student->id)->pluck('status');

        return match (true) {
            $statuses->contains('overdue') => 'overdue',
            $statuses->contains('pending') => 'pending',
            default => 'up_to_date',
        };
    }

    /**
     * A student with no attendance/coverage data yet (both null) is not
     * counted at-risk — that's absence of evidence, not evidence of
     * struggling (InstituteOverviewWidget's own original reasoning, S8
     * B5, carried over unchanged).
     *
     * S16 B1 (H10): threshold reads $student->tenant->at_risk_threshold_percent
     * when set, falling back to the existing hardcoded AT_RISK_THRESHOLD for
     * every tenant that never configures one — zero blast radius on existing
     * tenants (design doc §4 decision 12).
     *
     * @param  array{attendance_percent: ?float, coverage_percent: ?float, quiz_average_percent: ?float, fee_status: string, outstanding_invoices: Collection<int, FeeInvoice>}  $summary
     */
    public function isAtRisk(array $summary, User $student): bool
    {
        $threshold = $this->thresholdFor($student);

        return ($summary['attendance_percent'] !== null && $summary['attendance_percent'] < $threshold)
            || ($summary['coverage_percent'] !== null && $summary['coverage_percent'] < $threshold);
    }

    public function thresholdFor(User $student): float
    {
        return $student->tenant?->at_risk_threshold_percent ?? self::AT_RISK_THRESHOLD;
    }
}
