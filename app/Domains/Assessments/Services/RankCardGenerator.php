<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\RankCard;
use App\Domains\Assessments\Models\TestSeries;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S23 B15 (U15 pt.1, design doc §4 decisions 1 & 8). Rank/percentile/score
 * come straight from TestSeriesRankingService::rankingsFor() — no second
 * ranking math (design §2.3). Subject breakdown groups Question.concept the
 * same way S17/S22/this slice's own U11/U15 all reuse it as the one topic
 * axis (design §4 decision 1), summed from each quiz's best attempt for
 * this user — the exact "best attempt per quiz" selection
 * TestSeriesRankingService itself uses, so a breakdown's sum always equals
 * that ranking's total_score (QuizAttemptService::recomputeScore() already
 * guarantees attempt.score = sum of its own answers' score).
 *
 * On-demand only, dompdf via Pdf::loadView()->save() — same shape as
 * IdCardGenerator. Unlike IdCard's issue-once posture, a rank card can go
 * stale (a student attempts another quiz after first generation), so a
 * repeat call recomputes and overwrites the same row/file rather than
 * returning the first result unchanged. The double-click race IdCardGenerator
 * was hardened against in S15 B21 has no single existing child row to lock
 * before the first generate() — so this locks the parent TestSeries row
 * instead, serializing concurrent generate() calls for the whole series
 * (coarser than per-student, but this is an on-demand Admin action, not a
 * hot path, so the extra contention costs nothing real).
 */
class RankCardGenerator
{
    public function generate(TestSeries $series, User $user): RankCard
    {
        return DB::transaction(function () use ($series, $user): RankCard {
            $lockedSeries = TestSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            $ranking = app(TestSeriesRankingService::class)
                ->rankingsFor($lockedSeries, $user)
                ->firstWhere('user_id', $user->id) ?? ['total_score' => 0.0, 'rank' => null, 'percentile' => 0.0];

            $breakdown = $this->subjectBreakdown($lockedSeries, $user);

            $diskPath = "rank-cards/{$lockedSeries->tenant_id}/{$lockedSeries->id}/{$user->id}.pdf";

            Pdf::loadView('assessments.rank-card-pdf', [
                'student' => $user,
                'series' => $lockedSeries,
                'rank' => $ranking['rank'],
                'percentile' => $ranking['percentile'],
                'totalScore' => $ranking['total_score'],
                'breakdown' => $breakdown,
                'generatedAt' => now(),
            ])->save($diskPath, 'local');

            return RankCard::updateOrCreate(
                ['test_series_id' => $lockedSeries->id, 'user_id' => $user->id],
                ['pdf_path' => $diskPath],
            );
        });
    }

    /**
     * concept => summed score, from each quiz's best (highest-scoring,
     * non-in-progress) attempt for this user — a quiz never attempted
     * contributes nothing, same best-effort-partial-participation posture
     * TestSeriesRankingService already takes.
     *
     * @return Collection<string, float>
     */
    public function subjectBreakdown(TestSeries $series, User $user): Collection
    {
        $quizIds = Quiz::where('test_series_id', $series->id)
            ->where('tenant_id', $user->tenant_id)
            ->pluck('id');

        $bestAttemptIds = QuizAttempt::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizIds)
            ->where('status', '!=', 'in_progress')
            ->get()
            ->groupBy('quiz_id')
            ->map(fn (Collection $attempts) => $attempts->sortByDesc('score')->first()->id);

        return QuizAttempt::whereIn('id', $bestAttemptIds)
            ->with('answers.question')
            ->get()
            ->flatMap(fn (QuizAttempt $attempt) => $attempt->answers)
            ->groupBy(fn ($answer) => $answer->question->concept ?? 'General')
            ->map(fn (Collection $answers) => (float) $answers->sum('score'));
    }
}
