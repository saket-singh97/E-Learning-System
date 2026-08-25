<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * L13 — quiz results x concept map. Exact, lowercased-string equality
 * between `Question.concept` and `lesson_artifacts.concepts[].name` only —
 * no fuzzy/embedding matching (S17 design §4 decision 3). A concept phrased
 * differently by two authors (e.g. "Newton's 2nd Law" vs "Newton's Second
 * Law of Motion") simply won't link a lesson — a known, documented gap
 * rather than a heuristic that could just as easily mis-match two
 * genuinely different concepts that happen to share words.
 */
class WeakTopicDetectionService
{
    /**
     * Below this correctness ratio a concept is reported as weak — a
     * placeholder round number, not sourced from any pedagogy research,
     * same "deliberately doesn't invent a business number" posture
     * AiSpendCap::CAPS_USD already documents for this codebase.
     */
    private const WEAK_THRESHOLD = 0.6;

    /**
     * @return Collection<int, array{concept: string, correct_count: int, total_count: int, ratio: float, lessons: Collection<int, Lesson>}>
     */
    public function weakTopicsFor(User $student, int $courseId): Collection
    {
        $answers = QuizAnswer::query()
            ->whereNotNull('is_correct')
            ->whereHas('attempt', fn ($attemptQuery) => $attemptQuery->where('user_id', $student->id))
            ->whereHas('question', fn ($questionQuery) => $questionQuery->where('course_id', $courseId)->whereNotNull('concept'))
            ->with('question')
            ->get();

        $conceptToLessons = $this->conceptToLessonsFor($courseId);

        return $answers
            ->groupBy(fn (QuizAnswer $answer): string => $this->normalize($answer->question->concept))
            ->map(function (Collection $group, string $normalizedConcept) use ($conceptToLessons): array {
                $total = $group->count();
                $correct = $group->where('is_correct', true)->count();

                return [
                    'concept' => $group->first()->question->concept,
                    'correct_count' => $correct,
                    'total_count' => $total,
                    'ratio' => $total > 0 ? round($correct / $total, 2) : 0.0,
                    // A concept with no matching lesson_artifacts entry is
                    // still reported (empty Collection, not dropped
                    // entirely) — the caller decides how to render "no
                    // linked lesson yet."
                    'lessons' => $conceptToLessons->get($normalizedConcept, collect()),
                ];
            })
            ->filter(fn (array $row): bool => $row['ratio'] < self::WEAK_THRESHOLD)
            ->values();
    }

    /**
     * @return Collection<string, Collection<int, Lesson>>
     */
    protected function conceptToLessonsFor(int $courseId): Collection
    {
        $map = collect();

        LessonArtifact::query()
            ->where('status', 'ready')
            ->whereHas('lesson', fn ($lessonQuery) => $lessonQuery->whereHas(
                'section',
                fn ($sectionQuery) => $sectionQuery->where('course_id', $courseId),
            ))
            ->with('lesson')
            ->get()
            ->each(function (LessonArtifact $artifact) use ($map): void {
                if ($artifact->lesson === null) {
                    return;
                }

                foreach ($artifact->concepts ?? [] as $concept) {
                    $name = $concept['name'] ?? null;

                    if ($name === null) {
                        continue;
                    }

                    $normalized = $this->normalize($name);

                    $map->put($normalized, $map->get($normalized, collect())->push($artifact->lesson)->unique('id'));
                }
            });

        return $map;
    }

    protected function normalize(string $concept): string
    {
        return strtolower(trim($concept));
    }

    /**
     * L13 pt.3 — instructor/class-wide aggregate: how many of a batch's
     * actively-enrolled students are weak on each concept. Aggregate
     * counts only, never a per-student breakdown (that's weakTopicsFor()'s
     * own job, and only the student themself sees it — B8). Reads
     * Batches' Enrolment/Batch models directly, same "cross-domain *model*
     * reads are established precedent" posture Quiz::isVisibleTo()
     * already documents for its own Content model reads — not a
     * cross-domain *service* call, which CLAUDE.md's events-only rule
     * actually targets.
     *
     * @return Collection<int, array{concept: string, weak_student_count: int, total_students: int}>
     */
    public function classWideWeakTopicsFor(Batch $batch): Collection
    {
        $studentIds = Enrolment::where('batch_id', $batch->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $totalStudents = $studentIds->count();

        if ($totalStudents === 0) {
            return collect();
        }

        $counts = collect();

        User::whereIn('id', $studentIds)->get()->each(function (User $student) use ($batch, $counts): void {
            $this->weakTopicsFor($student, $batch->course_id)->each(
                fn (array $topic) => $counts->put($topic['concept'], $counts->get($topic['concept'], 0) + 1),
            );
        });

        return $counts
            ->map(fn (int $count, string $concept): array => [
                'concept' => $concept,
                'weak_student_count' => $count,
                'total_students' => $totalStudents,
            ])
            ->values()
            ->sortByDesc('weak_student_count')
            ->values();
    }
}
