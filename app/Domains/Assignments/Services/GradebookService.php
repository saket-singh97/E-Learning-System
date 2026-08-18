<?php

namespace App\Domains\Assignments\Services;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Caching\AggregateCache;
use Illuminate\Support\Collection;

/**
 * design doc §3.2's pinned contract, built in full here (both methods) even
 * though isComplete() has no real consumer until B11 — same "build ahead of
 * the first consumer" posture S1's lesson_artifacts schema and S5's
 * LiveSessionRoomCreated event already established for a class's own
 * complete contract landing in one block.
 *
 * Reads Assessments\Quiz/QuizAttempt directly (cross-domain *model* reads
 * are established precedent, CLAUDE.md) rather than calling
 * Comms\StudentMetrics::quizAveragePercent() — that method is tenant-wide,
 * not course-scoped, and averages *every* completed attempt rather than
 * each quiz's *best* one; confirmed by reading it (plan doc's own
 * instruction), not assumed to generalize.
 */
class GradebookService
{
    /**
     * J6's own threshold (B7) — deliberately a separate constant from
     * Comms\StudentMetrics::AT_RISK_THRESHOLD (S9 B13), not a shared one:
     * that one flags low attendance/video-coverage, this one flags a low
     * *graded* score. A student can be sitting in class every day (fine on
     * the attendance axis) while failing every quiz (at-risk on this one),
     * or the reverse — conflating them would silently change S9's existing
     * attendance-alert behaviour (design doc §2.3).
     */
    public const GRADEBOOK_AT_RISK_THRESHOLD = 60.0;

    public function __construct(private readonly AggregateCache $cache) {}

    /**
     * S12 B12 (design doc §4 decision 11) — cached whole, not partially:
     * every field this returns is a computed number/boolean derived from
     * QuizAttempt + AssignmentSubmission, and both are covered by an
     * explicit bust hook (`AssignmentGradingService::grade()`,
     * `QuizAttemptService::submit()`/`reviewAnswer()`) — unlike
     * `StudentMetrics::summarize()`, nothing here needs excluding.
     *
     * @return array{quiz_percent: ?float, assignment_percent: ?float, weighted_percent: float, is_passing: bool, is_at_risk: bool}
     */
    public function scoreFor(User $student, Course $course): array
    {
        return $this->cache->rememberGradebookScore(
            $student->tenant_id,
            $student->id,
            $course->id,
            fn (): array => $this->computeScoreFor($student, $course),
        );
    }

    /**
     * @return array{quiz_percent: ?float, assignment_percent: ?float, weighted_percent: float, is_passing: bool, is_at_risk: bool}
     */
    protected function computeScoreFor(User $student, Course $course): array
    {
        $quizPercent = $this->quizPercentFor($student, $course);
        $assignmentPercent = $this->assignmentPercentFor($student, $course);
        $weights = $course->gradebook_weights ?? [];

        // A component with zero gradeable items (or zero attempted/graded
        // by this student) drops out of the weighted average entirely —
        // its weight is excluded from the denominator too (renormalized),
        // rather than counting as zero and dragging the score down for a
        // category the course simply hasn't used yet.
        $components = collect([
            'quiz' => $quizPercent === null ? null : ['percent' => $quizPercent, 'weight' => (float) ($weights['quiz'] ?? 0)],
            'assignment' => $assignmentPercent === null ? null : ['percent' => $assignmentPercent, 'weight' => (float) ($weights['assignment'] ?? 0)],
        ])->filter();

        $totalWeight = $components->sum('weight');

        $weightedPercent = $totalWeight > 0
            ? round($components->sum(fn (array $c): float => $c['percent'] * $c['weight']) / $totalWeight, 1)
            : 0.0;

        // Same "absence of evidence is not evidence of struggling" posture
        // StudentMetrics::isAtRisk() already takes (S8 B5's own reasoning,
        // carried over) — a student with nothing graded yet ($totalWeight
        // === 0) is not flagged just because weighted_percent defaults to
        // 0.0.
        $hasData = $totalWeight > 0;

        return [
            'quiz_percent' => $quizPercent,
            'assignment_percent' => $assignmentPercent,
            'weighted_percent' => $weightedPercent,
            'is_passing' => $hasData && $weightedPercent >= (float) $course->passing_threshold,
            'is_at_risk' => $hasData && $weightedPercent < self::GRADEBOOK_AT_RISK_THRESHOLD,
        ];
    }

    /**
     * Completion (design doc §2.4 decision 7): every quiz and assignment
     * anchored to the course has at least one attempt/submission from this
     * student, AND the weighted score clears the course's own passing
     * threshold. A course with zero quizzes or zero assignments trivially
     * satisfies that half (Collection::every() on an empty collection is
     * true) — completion then rests entirely on whichever category the
     * course actually uses.
     */
    public function isComplete(User $student, Course $course): bool
    {
        $lessonIds = $this->courseLessonIds($course);

        $allQuizzesAttempted = Quiz::whereIn('lesson_id', $lessonIds)->pluck('id')
            ->every(fn (int $quizId): bool => QuizAttempt::where('quiz_id', $quizId)
                ->where('user_id', $student->id)
                ->where('status', '!=', 'in_progress')
                ->exists());

        $allAssignmentsSubmitted = Assignment::whereIn('lesson_id', $lessonIds)->pluck('id')
            ->every(fn (int $assignmentId): bool => AssignmentSubmission::where('assignment_id', $assignmentId)
                ->where('user_id', $student->id)
                ->exists());

        if (! $allQuizzesAttempted || ! $allAssignmentsSubmitted) {
            return false;
        }

        return $this->scoreFor($student, $course)['is_passing'];
    }

    /**
     * Three-state summary of `scoreFor()`'s own two independent boolean
     * flags — not-passing outranks at-risk (a passing-but-at-risk student
     * is real, `GradebookServiceTest` asserts exactly that combination).
     * Centralized here once a third caller (B9's CSV export) needed the
     * identical text a second Filament page (B8's `/teach` and `/admin`
     * `Gradebook`) already computed independently — same "third copy is
     * the bug class waiting to be forgotten" reasoning
     * `EscapesCsvFormulas` (S9 B11) was extracted for.
     *
     * @param  array{is_passing: bool, is_at_risk: bool}  $score
     */
    public function standingFor(array $score): string
    {
        // Real bug, caught by ExportGradebookCsvTest (B9): a student with
        // nothing graded yet has is_passing === false (same "no evidence"
        // default every other flag already guards), but that alone made
        // this method collapse "no data" into "Failing" — the exact
        // "absence of evidence is not evidence of struggling" mistake
        // is_passing/is_at_risk themselves were built to avoid, just one
        // level up. quiz_percent/assignment_percent both null is this
        // method's own signal for "nothing to judge yet."
        if ($score['quiz_percent'] === null && $score['assignment_percent'] === null) {
            return 'No data yet';
        }

        return match (true) {
            ! $score['is_passing'] => 'Failing',
            $score['is_at_risk'] => 'At risk',
            default => 'On track',
        };
    }

    /**
     * @return Collection<int, int>
     */
    protected function courseLessonIds(Course $course): Collection
    {
        return Lesson::whereIn('course_section_id', $course->sections()->pluck('id'))->pluck('id');
    }

    /**
     * Best completed attempt per quiz (not every attempt averaged, unlike
     * StudentMetrics::quizAveragePercent() — a resit shouldn't drag a
     * gradebook score down once the student improved on it), averaged
     * across whichever of the course's quizzes this student has attempted
     * at all. A quiz never attempted contributes nothing here (absence of
     * evidence), the same null-on-no-data posture StudentMetrics itself
     * takes when a student has no data at all.
     */
    protected function quizPercentFor(User $student, Course $course): ?float
    {
        $quizIds = Quiz::whereIn('lesson_id', $this->courseLessonIds($course))->pluck('id');

        if ($quizIds->isEmpty()) {
            return null;
        }

        $bestRatios = $quizIds
            ->map(function (int $quizId) use ($student): ?float {
                return QuizAttempt::where('quiz_id', $quizId)
                    ->where('user_id', $student->id)
                    ->where('status', '!=', 'in_progress')
                    ->whereNotNull('score')
                    ->get()
                    ->filter(fn (QuizAttempt $attempt): bool => (float) $attempt->max_score > 0)
                    ->map(fn (QuizAttempt $attempt): float => (float) $attempt->score / (float) $attempt->max_score)
                    ->max();
            })
            ->filter(fn (?float $ratio): bool => $ratio !== null);

        if ($bestRatios->isEmpty()) {
            return null;
        }

        return round($bestRatios->avg() * 100, 1);
    }

    /**
     * Latest *graded* version per assignment (design doc §3.2 — not the
     * highest-scoring version, unlike the quiz half's "best attempt"
     * symmetry): a resubmission is meant to supersede the grader's earlier
     * feedback, not be cherry-picked against it. An assignment with a
     * newer, still-ungraded version falls back to its latest *graded* one
     * rather than counting as no data.
     */
    protected function assignmentPercentFor(User $student, Course $course): ?float
    {
        $assignments = Assignment::whereIn('lesson_id', $this->courseLessonIds($course))->get();

        if ($assignments->isEmpty()) {
            return null;
        }

        $ratios = $assignments
            ->map(function (Assignment $assignment) use ($student): ?float {
                if ((float) $assignment->max_marks <= 0) {
                    return null;
                }

                $latestGraded = AssignmentSubmission::where('assignment_id', $assignment->id)
                    ->where('user_id', $student->id)
                    ->whereNotNull('graded_at')
                    ->latest('version_number')
                    ->first();

                if ($latestGraded === null) {
                    return null;
                }

                return (float) $latestGraded->marks_awarded / (float) $assignment->max_marks;
            })
            ->filter(fn (?float $ratio): bool => $ratio !== null);

        if ($ratios->isEmpty()) {
            return null;
        }

        return round($ratios->avg() * 100, 1);
    }
}
