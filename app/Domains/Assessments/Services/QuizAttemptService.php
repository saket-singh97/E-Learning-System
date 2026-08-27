<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Events\QuizAttemptSubmitted;
use App\Exceptions\QuizAttemptAlreadySubmittedException;
use App\Exceptions\QuizAttemptLimitExceededException;
use App\Exceptions\QuizNotAvailableException;
use App\Models\User;
use App\Support\Caching\AggregateCache;

/**
 * "Start" half of design doc §3.0's start/draw/submit/auto-grade contract is
 * B4/B5's; this block adds submit + auto-grade, plus the recompute a
 * short-answer review triggers.
 */
class QuizAttemptService
{
    public function __construct(private readonly AggregateCache $cache) {}
    public function start(Quiz $quiz, User $user): QuizAttempt
    {
        // Re-checked here, not just at the page gate: QuizRunner's own
        // startAttempt() action runs through /livewire/update, which has no
        // domain route constraint and therefore never passed through
        // ShowQuizController's gate at all (CLAUDE.md's tenant-context
        // section documents the same Livewire-bypasses-the-route gotcha for
        // a different gate). Shares Quiz::isVisibleTo() with the controller
        // so the two checks cannot drift apart.
        if (! $quiz->isVisibleTo($user)) {
            throw QuizNotAvailableException::forQuiz($quiz->id);
        }

        $attemptsUsed = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->count();

        if ($attemptsUsed >= $quiz->max_attempts) {
            throw QuizAttemptLimitExceededException::forQuiz($quiz->id, $quiz->max_attempts);
        }

        $previousAttempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->first();

        $questionIds = $this->draw($quiz, $previousAttempt?->question_ids ?? []);

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'question_ids' => $questionIds,
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now()->addMinutes($quiz->time_limit_minutes),
            'max_score' => count($questionIds),
        ]);
    }

    /**
     * MCQ/true_false/numerical grade instantly, synchronously, inside this
     * same call — I4's "within 5s" budget doesn't need a queued job.
     * short_answer lands `pending_review` (unless left blank, which is
     * unambiguously wrong and needs no human read) for reviewAnswer() to
     * finish later. One QuizAnswer row per drawn question, answered or not
     * — an unanswered row still exists (`response = null`) so the review
     * queue and the score sum both see a complete, gap-free set.
     *
     * $attemptStatus distinguishes a student's own submit (default) from
     * AutoSubmitExpiredQuizAttempts's sweep (B7, passes 'auto_submitted') —
     * same grading path either way, only the attempt's own status differs.
     *
     * A response passed explicitly here always wins over whatever
     * saveAnswer() already autosaved for that question — this is the
     * caller's own final say (a student's own Submit click, or any future
     * caller). Anything not passed falls back to the autosaved draft
     * (S9 B7) instead of defaulting to blank, which is exactly what makes
     * AutoSubmitExpiredQuizAttempts's own empty `[]` now grade what the
     * student actually answered, not a blank attempt — closing the gap
     * that job's own docblock named as a known, accepted limitation.
     *
     * @param  array<int, mixed>  $responses  question_id => raw response
     */
    public function submit(QuizAttempt $attempt, array $responses, string $attemptStatus = 'submitted'): QuizAttempt
    {
        if ($attempt->status !== 'in_progress') {
            throw QuizAttemptAlreadySubmittedException::forAttempt($attempt->id);
        }

        $questions = Question::whereIn('id', $attempt->question_ids)->get()->keyBy('id');
        $draftResponses = $attempt->answers()->pluck('response', 'question_id');

        foreach ($attempt->question_ids as $questionId) {
            $question = $questions->get($questionId);

            if ($question === null) {
                continue;
            }

            $response = array_key_exists($questionId, $responses)
                ? $responses[$questionId]
                : $draftResponses->get($questionId);

            [$isCorrect, $score, $answerStatus] = $this->grade($question, $response);

            QuizAnswer::updateOrCreate(
                ['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id],
                [
                    'response' => $response,
                    'is_correct' => $isCorrect,
                    'score' => $score,
                    'status' => $answerStatus,
                ],
            );
        }

        $attempt->update([
            'status' => $attemptStatus,
            'submitted_at' => now(),
            // Recomputed from the questions actually found, not trusted
            // from start()'s draw-time count: a question soft-deleted
            // between draw and submit (an instructor retiring a bad
            // question is a routine action, and can land while a student
            // has it mid-attempt) is silently skipped above — without this,
            // max_score would still count it, under-representing a
            // student's true achievable score with no explanation. Found
            // during B8's review pass, not exercised by any earlier block's
            // tests.
            'max_score' => $questions->count(),
            'score' => $this->recomputeScore($attempt),
        ]);

        $this->bustCachesFor($attempt);

        // S19 B13 (N18) — fires only on this genuine in_progress -> submitted
        // transition (the guard clause above throws on any later call for
        // the same attempt), so Analytics' XP listener can never double-award.
        event(new QuizAttemptSubmitted($attempt->user_id, $attempt->tenant_id, $attempt->id));

        return $attempt->fresh();
    }

    /**
     * Incremental persistence (S9 B7) — QuizRunner calls this on
     * blur/selection-change, not on every keystroke, so an expired or
     * never-submitted attempt still has a real record of what the student
     * actually answered for AutoSubmitExpiredQuizAttempts to grade.
     * Deliberately does not grade anything here — grading branches by
     * question type and, for short_answer, needs the row to reach the
     * review queue only once, at actual submit time; an ungraded 'draft'
     * status keeps this row out of QuizAnswerResource's own review queue
     * entirely while the attempt is still in progress. A silent no-op once
     * the attempt is no longer in_progress (expired between keystrokes, or
     * already submitted from another tab) — the student finds out via
     * submitAttempt()'s own check, not from a failed background autosave.
     *
     * S16 B10 (I8 pt.2) — `$elapsedSeconds` is a client-reported delta
     * since the last autosave for this question (not a running total), so
     * it's added to whatever was already recorded, never overwrites it —
     * a question autosaved 5 times racks up 5 deltas' worth of time, not
     * just the last one's.
     */
    public function saveAnswer(QuizAttempt $attempt, int $questionId, mixed $response, int $elapsedSeconds = 0): void
    {
        if ($attempt->status !== 'in_progress') {
            return;
        }

        if (! in_array($questionId, $attempt->question_ids, true)) {
            return;
        }

        $existing = QuizAnswer::where('quiz_attempt_id', $attempt->id)->where('question_id', $questionId)->first();
        $accumulatedSeconds = ($existing->time_spent_seconds ?? 0) + max(0, $elapsedSeconds);

        QuizAnswer::updateOrCreate(
            ['quiz_attempt_id' => $attempt->id, 'question_id' => $questionId],
            ['response' => $response, 'status' => 'draft', 'time_spent_seconds' => $accumulatedSeconds],
        );
    }

    /**
     * The only path that ever grades a pending_review short_answer answer.
     * Score is the reviewer's own call (0..1, one point per question,
     * matching start()'s max_score = count(question_ids)) — clamped by the
     * Filament form, not re-validated here, since this service has no
     * other caller yet. Recomputes the parent attempt's score itself
     * rather than leaving that to the caller, so a review can never land
     * without the attempt's own total catching up to it (this block's own
     * plan note: verified via a dedicated test, not just at submit time).
     *
     * S12 B12 — also busts the gradebook/student-metrics caches, same as
     * submit() itself: a short-answer review changes the exact same
     * `score`/`max_score` data those aggregates read, even though decision
     * 11's own bust list names "QuizAttempt submitted," not "reviewed" —
     * leaving this uninvalidated would let a review silently disagree with
     * a cached score until the next unrelated bust or the TTL catches up,
     * a real staleness gap the design doc's own wording didn't spell out
     * but the underlying data change makes necessary either way.
     */
    public function reviewAnswer(QuizAnswer $answer, float $score, User $reviewer): QuizAnswer
    {
        $answer->update([
            'score' => $score,
            'is_correct' => $score > 0,
            'status' => 'reviewed',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $attempt = $answer->attempt;
        $attempt->update(['score' => $this->recomputeScore($attempt)]);

        $this->bustCachesFor($attempt);

        return $answer->fresh();
    }

    /**
     * A missing quiz/lesson/section/course link skips the gradebook bust
     * (same defensive posture `AssignmentGradingService::bustGradebookCache()`
     * takes) — the attempt itself already saved successfully above.
     */
    protected function bustCachesFor(QuizAttempt $attempt): void
    {
        $this->cache->forgetStudentMetrics($attempt->tenant_id, $attempt->user_id);
        $this->cache->forgetInstituteOverview($attempt->tenant_id);

        $course = $attempt->quiz?->lesson?->section?->course;

        if ($course !== null) {
            $this->cache->forgetGradebookScore($attempt->tenant_id, $attempt->user_id, $course->id);
        }
    }

    /**
     * Running partial sum of every graded answer (design doc §3.3: "score
     * = sum of graded answers; stays partial until every pending_review
     * answer is reviewed") — not held back to null while a short_answer
     * question is still awaiting review, so a mostly-objective attempt
     * shows a meaningful score immediately instead of a blank one.
     */
    protected function recomputeScore(QuizAttempt $attempt): float
    {
        return (float) $attempt->answers()->whereNotNull('score')->sum('score');
    }

    /**
     * @return array{0: ?bool, 1: ?float, 2: string}
     */
    protected function grade(Question $question, mixed $response): array
    {
        return match ($question->type) {
            'mcq', 'true_false' => $this->gradeExactMatch($question, $response),
            'numerical' => $this->gradeNumerical($question, $response),
            'short_answer' => $this->gradeShortAnswer($response),
            'multi_select' => $this->gradeMultiSelect($question, $response),
            'fill_in_blank' => $this->gradeFillInBlank($question, $response),
            'matching' => $this->gradeMatching($question, $response),
            'ordering' => $this->gradeOrdering($question, $response),
            default => [false, 0.0, 'auto_graded'],
        };
    }

    /**
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeExactMatch(Question $question, mixed $response): array
    {
        $isCorrect = $response !== null && (string) $response === (string) $question->correct_answer;

        return [$isCorrect, $isCorrect ? 1.0 : 0.0, 'auto_graded'];
    }

    /**
     * Tolerance boundary is inclusive — a response exactly `tolerance` away
     * from the correct value still counts as correct (I7).
     *
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeNumerical(Question $question, mixed $response): array
    {
        if ($response === null || ! is_numeric($response)) {
            return [false, 0.0, 'auto_graded'];
        }

        $value = (float) ($question->correct_answer['value'] ?? 0);
        $tolerance = (float) ($question->correct_answer['tolerance'] ?? 0);

        $isCorrect = abs((float) $response - $value) <= $tolerance;

        return [$isCorrect, $isCorrect ? 1.0 : 0.0, 'auto_graded'];
    }

    /**
     * A blank response is unambiguously wrong and needs no human read —
     * only a genuinely non-empty answer joins the review queue.
     *
     * @return array{0: null|bool, 1: null|float, 2: string}
     */
    protected function gradeShortAnswer(mixed $response): array
    {
        if ($response === null || trim((string) $response) === '') {
            return [false, 0.0, 'auto_graded'];
        }

        return [null, null, 'pending_review'];
    }

    /**
     * S16 B7 (I6, design doc §4 decision 3) — partial credit is a simple
     * fraction of correct selections, never negative: (correct selections
     * − incorrect selections) / total correct options, floored at 0. A
     * question with zero `correct_option_ids` configured has nothing to
     * score against and grades as wrong rather than dividing by zero.
     *
     * `is_correct` follows `reviewAnswer()`'s own established meaning for
     * a fractional score elsewhere in this class — "earned some credit"
     * (`score > 0`), not "every part right." `score` is rounded to 4dp in
     * memory; `QuizAnswer.score`'s own `decimal:2` cast rounds it further
     * on write/read, same as every other type's score.
     *
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeMultiSelect(Question $question, mixed $response): array
    {
        $correctIds = collect($question->correct_answer['correct_option_ids'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $totalCorrect = count($correctIds);

        if ($totalCorrect === 0 || ! is_array($response)) {
            return [false, 0.0, 'auto_graded'];
        }

        $selectedIds = array_map('strval', $response);
        $correctSelections = count(array_intersect($selectedIds, $correctIds));
        $incorrectSelections = count(array_diff($selectedIds, $correctIds));

        $score = max(0.0, ($correctSelections - $incorrectSelections) / $totalCorrect);

        return [$score > 0, round($score, 4), 'auto_graded'];
    }

    /**
     * Case-insensitive exact match against a list of accepted answers, not
     * fuzzy matching — same binary (exact match or zero) shape
     * `mcq`/`true_false` already have, no partial credit (design doc §4
     * decision 3).
     *
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeFillInBlank(Question $question, mixed $response): array
    {
        if ($response === null || trim((string) $response) === '') {
            return [false, 0.0, 'auto_graded'];
        }

        $accepted = collect($question->correct_answer['accepted_answers'] ?? [])
            ->map(fn (mixed $answer): string => mb_strtolower(trim((string) $answer)))
            ->all();

        $isCorrect = in_array(mb_strtolower(trim((string) $response)), $accepted, true);

        return [$isCorrect, $isCorrect ? 1.0 : 0.0, 'auto_graded'];
    }

    /**
     * Fraction of the configured `pairs` the response also pairs
     * correctly — response shape is `{left_id: right_id, ...}`, keyed the
     * same way as `pairs`' own left side, both sides string-compared so a
     * numeric or string id form on either side still matches.
     *
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeMatching(Question $question, mixed $response): array
    {
        $correctPairs = collect($question->correct_answer['pairs'] ?? []);
        $totalPairs = $correctPairs->count();

        if ($totalPairs === 0 || ! is_array($response)) {
            return [false, 0.0, 'auto_graded'];
        }

        $responseMap = collect($response)->mapWithKeys(
            fn (mixed $value, int|string $key): array => [(string) $key => (string) $value]
        );

        $correctCount = $correctPairs->filter(
            fn (array $pair): bool => $responseMap->get((string) $pair[0]) === (string) $pair[1]
        )->count();

        $score = $correctCount / $totalPairs;

        return [$score > 0, round($score, 4), 'auto_graded'];
    }

    /**
     * Fraction of positions where the response's own sequence matches
     * `correct_sequence` index-for-index — a response shorter than the
     * correct sequence simply scores 0 on every position past its own
     * length, not an error.
     *
     * @return array{0: bool, 1: float, 2: string}
     */
    protected function gradeOrdering(Question $question, mixed $response): array
    {
        $correctSequence = collect($question->correct_answer['correct_sequence'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->values();

        $total = $correctSequence->count();

        if ($total === 0 || ! is_array($response)) {
            return [false, 0.0, 'auto_graded'];
        }

        $responseSequence = collect($response)->map(fn (mixed $id): string => (string) $id)->values();

        $correctPositions = $correctSequence->filter(
            fn (string $id, int $index): bool => $responseSequence->get($index) === $id
        )->count();

        $score = $correctPositions / $total;

        return [$score > 0, round($score, 4), 'auto_graded'];
    }

    /**
     * Satisfies the blueprint from the approved pool (I2), one line at a
     * time, never drawing the same question twice within one attempt.
     * Within each line, prefers questions the previous attempt didn't use;
     * if the line's approved pool is too small to both satisfy the count
     * *and* avoid every previously-used question, satisfies the count and
     * allows the repeat rather than under-filling the quiz (documented,
     * not left implicit — see design doc §2.4 decision 3 and this block's
     * own plan note).
     *
     * Public, not protected — S16 B15 (I11 pt.2) reuses this directly to
     * fix an offline OMR template's own question set once at generation
     * time, the same draw an online attempt's own `start()` already makes
     * (design doc §3.2's own "reused not duplicated" framing).
     *
     * @param  array<int, int>  $previousQuestionIds
     * @return array<int, int>
     */
    public function draw(Quiz $quiz, array $previousQuestionIds): array
    {
        $courseId = $quiz->lesson->section->course_id;
        $drawn = [];

        foreach ($quiz->blueprint as $line) {
            $count = max(1, (int) ($line['count'] ?? 1));

            $pool = Question::query()
                ->where('course_id', $courseId)
                ->where('status', 'approved')
                ->whereNotIn('id', $drawn)
                ->when(! empty($line['type']), fn ($query) => $query->where('type', $line['type']))
                ->when(! empty($line['difficulty']), fn ($query) => $query->where('difficulty', $line['difficulty']))
                ->when(! empty($line['concept']), fn ($query) => $query->where('concept', $line['concept']))
                ->inRandomOrder()
                ->get();

            $fresh = $pool->whereNotIn('id', $previousQuestionIds);
            $picked = $fresh->take($count);

            if ($picked->count() < $count) {
                $stale = $pool->whereNotIn('id', $picked->pluck('id'))->take($count - $picked->count());
                $picked = $picked->merge($stale);
            }

            $drawn = array_merge($drawn, $picked->pluck('id')->all());
        }

        return $drawn;
    }
}
