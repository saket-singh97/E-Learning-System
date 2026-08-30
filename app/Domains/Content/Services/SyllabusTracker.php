<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Percent of a course's syllabus-mapped lessons a viewer has completed,
 * grouped by board_or_exam (design doc §4 decision 1's SyllabusTopic
 * grouping). Reuses LessonCompletion's existing per-lesson-type heuristic
 * (S13 C8) rather than a second "done" signal.
 */
class SyllabusTracker
{
    public function __construct(private readonly LessonCompletion $completion) {}

    /**
     * @return array<string, float> board_or_exam => percent complete (0-100, 1dp).
     *                               Empty when the course has no syllabus
     *                               topics mapped at all.
     */
    public function percentCompleteFor(Course $course, User $viewer): array
    {
        return $course->syllabusTopics()
            ->with('lessons')
            ->get()
            ->groupBy('board_or_exam')
            ->map(function (Collection $topics) use ($viewer): float {
                $lessons = $topics->flatMap->lessons->unique('id');

                // A board_or_exam group whose topics have no lessons
                // attached yet has no denominator to divide by — 0%, not a
                // crash.
                if ($lessons->isEmpty()) {
                    return 0.0;
                }

                $completedCount = $lessons->filter(
                    fn (Lesson $lesson): bool => $this->completion->isComplete($lesson, $viewer),
                )->count();

                return round($completedCount / $lessons->count() * 100, 1);
            })
            ->all();
    }
}
