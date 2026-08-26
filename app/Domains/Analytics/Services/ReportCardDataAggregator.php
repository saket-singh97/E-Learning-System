<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A5 pt.1 (design doc §2.1) — the three numbers a monthly parent report
 * card needs, each scoped to one calendar month. Attendance/quiz math
 * duplicates StudentMetrics's own established formulas (BatchSession rows
 * as the only reliable present/absent denominator; quiz average is the
 * mean of each completed attempt's own score/max_score ratio, not summed
 * points) rather than calling it, since StudentMetrics has no month-
 * scoping parameter and adding one there would ripple into its own
 * all-time parent-portal/at-risk callers — same "cross-domain model reads
 * are precedent, duplicating a ~10-line formula beats reshaping an
 * unrelated caller's contract" judgment call this codebase already makes
 * elsewhere (e.g. EngagementMetricsService's own mergedCoveredSeconds).
 *
 * Engagement ratio reuses EngagementMetricsService::ratioFor() (B0)
 * unmodified — "for that calendar month" scopes *which lessons* count
 * (any lesson the student has a VideoWatchEvent on that month), not the
 * ratio formula itself, since engagement ratio is inherently a cumulative
 * per-lesson measure with no month-scoped variant of its own.
 *
 * Zero activity in a month returns zeroed-out values, never null/error —
 * a report card with nothing to show is still a valid report card.
 */
class ReportCardDataAggregator
{
    public function __construct(private readonly EngagementMetricsService $engagementMetrics) {}

    /**
     * @return array{attendance_percent: float, quiz_average_percent: float, engagement_ratio_percent: float}
     */
    public function summarize(User $student, CarbonInterface $periodMonth): array
    {
        $start = $periodMonth->clone()->startOfMonth();
        $end = $periodMonth->clone()->endOfMonth();

        return [
            'attendance_percent' => $this->attendancePercent($student, $start, $end),
            'quiz_average_percent' => $this->quizAveragePercent($student, $start, $end),
            'engagement_ratio_percent' => $this->engagementRatioPercent($student, $start, $end),
        ];
    }

    protected function attendancePercent(User $student, CarbonInterface $start, CarbonInterface $end): float
    {
        $records = AttendanceRecord::where('user_id', $student->id)
            ->where('attendable_type', BatchSession::class)
            ->whereBetween('recorded_at', [$start, $end])
            ->get();

        if ($records->isEmpty()) {
            return 0.0;
        }

        return round($records->where('status', 'present')->count() / $records->count() * 100, 1);
    }

    protected function quizAveragePercent(User $student, CarbonInterface $start, CarbonInterface $end): float
    {
        $attempts = QuizAttempt::where('user_id', $student->id)
            ->where('status', '!=', 'in_progress')
            ->whereNotNull('score')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $percentages = $attempts
            ->filter(fn (QuizAttempt $attempt): bool => (float) $attempt->max_score > 0)
            ->map(fn (QuizAttempt $attempt): float => (float) $attempt->score / (float) $attempt->max_score * 100);

        if ($percentages->isEmpty()) {
            return 0.0;
        }

        return round($percentages->avg(), 1);
    }

    protected function engagementRatioPercent(User $student, CarbonInterface $start, CarbonInterface $end): float
    {
        $lessonIds = VideoWatchEvent::where('user_id', $student->id)
            ->whereBetween('created_at', [$start, $end])
            ->distinct()
            ->pluck('lesson_id');

        if ($lessonIds->isEmpty()) {
            return 0.0;
        }

        $ratios = Lesson::whereIn('id', $lessonIds)
            ->get()
            ->map(fn (Lesson $lesson): float => $this->engagementMetrics->ratioFor($student, $lesson));

        return round($ratios->avg() * 100, 1);
    }
}
