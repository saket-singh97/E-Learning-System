<?php

namespace App\Domains\Growth\Services;

use App\Domains\Assessments\Models\Question;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;

/**
 * S23 B13 (U12 pt.2, design doc §4 decision 2) — every existing
 * `QuizAttempt` FK's to an authenticated enrolled `User`; a prospect
 * registering for a scholarship test isn't one. Rather than relaxing that
 * FK for every other assessment feature that trusts it, this grades the
 * submitted answers in-memory and writes `score` directly onto the
 * registration — no `QuizAttempt`/`QuizAnswer` row ever created, so the
 * enrolled-attempt path stays untouched.
 *
 * The per-type grading dispatch below is a deliberate duplicate of
 * `QuizAttemptService`'s own (protected, Assessments-domain) grading
 * methods, not a cross-domain call to it — same reasoning
 * `StudentMetrics::coveragePercent()` already documents for duplicating
 * `RecordVideoCoverageAttendance`'s merge-interval math instead of calling
 * it: the source is a service method, not a model, so calling into it
 * would be a cross-domain service call (CLAUDE.md: domains talk through
 * Events only), not the cross-domain *model* read this class does for
 * `Question` itself. Kept in exact lockstep with that method so a
 * scholarship-test score means the same thing an enrolled `QuizAttempt`'s
 * would for the same question+response.
 */
class ScholarshipTestGradingService
{
    /**
     * S23 B20 review-pass fix — the registration flow (B12) never actually
     * presented this test's own questions, so `grade()` below had no
     * caller anywhere in the app (found in independent review: U12 shipped
     * as a lead-capture form, not a test). Draws this test's linked
     * `Quiz.blueprint` the same way `QuizAttemptService::draw()` does for
     * an enrolled attempt — duplicated, not called, for the identical
     * cross-domain-service reason this class's own docblock already gives
     * for `grade1()`'s dispatch. No previous-attempt avoidance (that
     * method's own second parameter): a scholarship test is one-shot, no
     * repeat-attempt concept exists for an unauthenticated prospect.
     *
     * @return array<int, int>
     */
    public function drawQuestions(ScholarshipTest $test): array
    {
        $quiz = $test->quiz;
        $courseId = $quiz->lesson->section->course_id;
        $drawn = [];

        foreach ($quiz->blueprint as $line) {
            $count = max(1, (int) ($line['count'] ?? 1));

            $picked = Question::query()
                ->where('course_id', $courseId)
                ->where('status', 'approved')
                ->whereNotIn('id', $drawn)
                ->when(! empty($line['type']), fn ($query) => $query->where('type', $line['type']))
                ->when(! empty($line['difficulty']), fn ($query) => $query->where('difficulty', $line['difficulty']))
                ->when(! empty($line['concept']), fn ($query) => $query->where('concept', $line['concept']))
                ->inRandomOrder()
                ->take($count)
                ->pluck('id');

            $drawn = array_merge($drawn, $picked->all());
        }

        return $drawn;
    }

    /**
     * Idempotent by returning the already-graded row unchanged, not by
     * throwing — same posture QuestionGenerator::fromSeedQuestions()/
     * DailyPracticeSetGenerator already established for a call that's safe
     * to repeat. A registration's `score` is only ever set once: a second
     * call with a different `$answers` must not silently overwrite an
     * already-final score.
     *
     * @param  array<int, mixed>  $answers  question_id => raw response
     */
    public function grade(ScholarshipTestRegistration $registration, array $answers): ScholarshipTestRegistration
    {
        if ($registration->score !== null) {
            return $registration;
        }

        $quiz = $registration->scholarshipTest->quiz;
        $courseId = $quiz->lesson->section->course_id;

        // Same pool scoping QuizAttemptService::draw() applies — this
        // course's own approved questions only, so an id from a different
        // course/quiz (tampered payload or a stale link) can't be smuggled
        // into the score.
        $questions = Question::whereIn('id', array_keys($answers))
            ->where('course_id', $courseId)
            ->where('status', 'approved')
            ->get();

        $score = $questions->sum(fn (Question $question) => $this->grade1($question, $answers[$question->id] ?? null));

        $registration->update(['score' => $score]);

        return $registration->fresh();
    }

    protected function grade1(Question $question, mixed $response): float
    {
        return match ($question->type) {
            'mcq', 'true_false' => $this->gradeExactMatch($question, $response),
            'numerical' => $this->gradeNumerical($question, $response),
            'multi_select' => $this->gradeMultiSelect($question, $response),
            'fill_in_blank' => $this->gradeFillInBlank($question, $response),
            'matching' => $this->gradeMatching($question, $response),
            'ordering' => $this->gradeOrdering($question, $response),
            // short_answer needs a human reviewer in the enrolled-attempt
            // path too (QuizAttemptService::gradeShortAnswer()) — no review
            // queue exists for an unauthenticated prospect, so it scores 0
            // here rather than landing somewhere nobody will ever read.
            default => 0.0,
        };
    }

    protected function gradeExactMatch(Question $question, mixed $response): float
    {
        return $response !== null && (string) $response === (string) $question->correct_answer ? 1.0 : 0.0;
    }

    protected function gradeNumerical(Question $question, mixed $response): float
    {
        if ($response === null || ! is_numeric($response)) {
            return 0.0;
        }

        $value = (float) ($question->correct_answer['value'] ?? 0);
        $tolerance = (float) ($question->correct_answer['tolerance'] ?? 0);

        return abs((float) $response - $value) <= $tolerance ? 1.0 : 0.0;
    }

    protected function gradeMultiSelect(Question $question, mixed $response): float
    {
        $correctIds = collect($question->correct_answer['correct_option_ids'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $totalCorrect = count($correctIds);

        if ($totalCorrect === 0 || ! is_array($response)) {
            return 0.0;
        }

        $selectedIds = array_map('strval', $response);
        $correctSelections = count(array_intersect($selectedIds, $correctIds));
        $incorrectSelections = count(array_diff($selectedIds, $correctIds));

        return round(max(0.0, ($correctSelections - $incorrectSelections) / $totalCorrect), 4);
    }

    protected function gradeFillInBlank(Question $question, mixed $response): float
    {
        if ($response === null || trim((string) $response) === '') {
            return 0.0;
        }

        $accepted = collect($question->correct_answer['accepted_answers'] ?? [])
            ->map(fn (mixed $answer): string => mb_strtolower(trim((string) $answer)))
            ->all();

        return in_array(mb_strtolower(trim((string) $response)), $accepted, true) ? 1.0 : 0.0;
    }

    protected function gradeMatching(Question $question, mixed $response): float
    {
        $correctPairs = collect($question->correct_answer['pairs'] ?? []);
        $totalPairs = $correctPairs->count();

        if ($totalPairs === 0 || ! is_array($response)) {
            return 0.0;
        }

        $responseMap = collect($response)->mapWithKeys(
            fn (mixed $value, int|string $key): array => [(string) $key => (string) $value]
        );

        $correctCount = $correctPairs->filter(
            fn (array $pair): bool => $responseMap->get((string) $pair[0]) === (string) $pair[1]
        )->count();

        return round($correctCount / $totalPairs, 4);
    }

    protected function gradeOrdering(Question $question, mixed $response): float
    {
        $correctSequence = collect($question->correct_answer['correct_sequence'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->values();

        $total = $correctSequence->count();

        if ($total === 0 || ! is_array($response)) {
            return 0.0;
        }

        $responseSequence = collect($response)->map(fn (mixed $id): string => (string) $id)->values();

        $correctPositions = $correctSequence->filter(
            fn (string $id, int $index): bool => $responseSequence->get($index) === $id
        )->count();

        return round($correctPositions / $total, 4);
    }
}
