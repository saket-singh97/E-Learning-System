<?php

namespace App\Livewire;

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\QuizProctoringEvent;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Exceptions\QuizAttemptLimitExceededException;
use App\Exceptions\QuizNotAvailableException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Student-facing attempt UI (design doc §3.0), reached from the syllabus
 * tree's quiz rows via ShowQuizController. Start (B5) + submit/results (B6)
 * both live here — one component for the whole attempt lifecycle.
 */
class QuizRunner extends Component
{
    public Quiz $quiz;

    public array $responses = [];

    public function mount(Quiz $quiz): void
    {
        $this->quiz = $quiz;
    }

    /**
     * Re-verified by QuizAttemptService::start() itself (via
     * Quiz::isVisibleTo(), the same gate ShowQuizController's page load
     * already ran) rather than trusted from the page load — this action
     * fires through /livewire/update, which has no domain route constraint
     * and so never passed through that controller at all.
     */
    public function startAttempt(): void
    {
        $this->resetErrorBag();

        $user = auth()->user();

        if ($user === null) {
            $this->addError('attempt', 'Sign in to attempt this quiz.');

            return;
        }

        try {
            app(QuizAttemptService::class)->start($this->quiz, $user);
        } catch (QuizAttemptLimitExceededException) {
            $this->addError('attempt', 'You have used all your attempts for this quiz.');

            return;
        } catch (QuizNotAvailableException) {
            $this->addError('attempt', 'This quiz is not currently available.');

            return;
        }

        $this->responses = [];
    }

    /**
     * Incremental persistence (S9 B7), fired on blur/selection-change from
     * the view — not every keystroke, which would be too chatty for a
     * plain text/numerical field. Re-derives the in_progress attempt from
     * the DB rather than trusting any client-held id, same posture
     * submitAttempt() already uses.
     */
    public function saveAnswer(int $questionId): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $attempt = $this->activeAttempt($user->id);

        if ($attempt === null) {
            return;
        }

        app(QuizAttemptService::class)->saveAnswer($attempt, $questionId, $this->responses[$questionId] ?? null);
    }

    /**
     * Re-derives the in_progress attempt from the DB rather than trusting
     * any client-held id — same ownership-by-query posture render() already
     * uses, so a student can never submit against another user's attempt.
     */
    public function submitAttempt(): void
    {
        $this->resetErrorBag();

        $user = auth()->user();

        if ($user === null) {
            $this->addError('attempt', 'Sign in to attempt this quiz.');

            return;
        }

        $attempt = $this->activeAttempt($user->id);

        if ($attempt === null) {
            $this->addError('attempt', 'No attempt is currently in progress.');

            return;
        }

        app(QuizAttemptService::class)->submit($attempt, $this->responses);

        $this->responses = [];
    }

    /**
     * S16 B9 (I8 pt.1) — client-side listeners (Page Visibility API,
     * Fullscreen API, copy/paste DOM events) call this once triggered.
     * Detection/evidence only, not prevention (design doc §4 decision 4).
     * Every guard here is server-side, not trusted from the client: an
     * unrecognised event type, a disabled quiz, or no active attempt for
     * this viewer/quiz all silently no-op rather than writing a row —
     * the JS only ever attaches these listeners when proctoring is
     * enabled, but a crafted Livewire call must not be able to write an
     * event for a quiz that never opted in.
     */
    public function logProctoringEvent(string $eventType): void
    {
        if (! in_array($eventType, QuizProctoringEvent::EVENT_TYPES, true) || ! $this->quiz->proctoring_enabled) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $attempt = $this->activeAttempt($user->id);

        if ($attempt === null) {
            return;
        }

        QuizProctoringEvent::create([
            'quiz_attempt_id' => $attempt->id,
            'event_type' => $eventType,
            'occurred_at' => now(),
        ]);
    }

    public function render(): View
    {
        $user = auth()->user();

        $attempt = $user === null ? null : $this->activeAttempt($user->id);

        $lastCompletedAttempt = ($user !== null && $attempt === null)
            ? QuizAttempt::where('quiz_id', $this->quiz->id)
                ->where('user_id', $user->id)
                ->where('status', '!=', 'in_progress')
                ->latest('submitted_at')
                ->first()
            : null;

        $questionIds = $attempt?->question_ids ?? [];

        $questions = Question::whereIn('id', $questionIds)
            ->get()
            ->sortBy(fn (Question $question): int => array_search($question->id, $questionIds, true))
            ->values();

        $results = $lastCompletedAttempt === null ? null : $this->resultsFor($lastCompletedAttempt);

        $attemptsUsed = $user === null ? 0 : QuizAttempt::where('quiz_id', $this->quiz->id)
            ->where('user_id', $user->id)
            ->count();

        // M9: raw score sequence across this student's own attempts on this
        // one quiz — not a chart, not a cross-quiz dashboard, just the list.
        $attemptHistory = $user === null ? collect() : QuizAttempt::where('quiz_id', $this->quiz->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'in_progress')
            ->orderBy('started_at')
            ->get();

        return view('livewire.quiz-runner', [
            'attempt' => $attempt,
            'questions' => $questions,
            'attemptsRemaining' => max(0, $this->quiz->max_attempts - $attemptsUsed),
            'lastCompletedAttempt' => $lastCompletedAttempt,
            'results' => $results,
            'attemptHistory' => $attemptHistory,
        ]);
    }

    protected function activeAttempt(int $userId): ?QuizAttempt
    {
        return QuizAttempt::where('quiz_id', $this->quiz->id)
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->latest('started_at')
            ->first();
    }

    /**
     * @return Collection<int, array{question: ?Question, answer: QuizAnswer}>
     */
    protected function resultsFor(QuizAttempt $attempt): Collection
    {
        $questions = Question::whereIn('id', $attempt->question_ids)->get()->keyBy('id');

        return $attempt->answers()->get()
            ->sortBy(fn (QuizAnswer $answer): int => array_search($answer->question_id, $attempt->question_ids, true))
            ->map(fn (QuizAnswer $answer): array => [
                'question' => $questions->get($answer->question_id),
                'answer' => $answer,
            ])
            ->values();
    }
}
