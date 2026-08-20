<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Lesson;
use App\Exceptions\CircularPrerequisiteException;

/**
 * Circular-dependency check runs at write time, not read time (design doc
 * decision 6) — cheaper to reject the one bad edit than to detect and
 * untangle a cycle later during unlock evaluation on every page load.
 */
class PrerequisiteGraph
{
    /**
     * Single write path for the lesson_prerequisites pivot — Lesson::
     * prerequisites() itself is read-only by convention, every caller
     * (Filament, tests, future callers) goes through here.
     */
    public function addPrerequisite(Lesson $lesson, Lesson $prerequisite): void
    {
        if ($this->wouldCreateCycle($lesson, $prerequisite)) {
            throw CircularPrerequisiteException::forEdge($lesson, $prerequisite);
        }

        $lesson->prerequisites()->syncWithoutDetaching([$prerequisite->id]);
    }

    /**
     * Pure check, no write — used by Filament's own inline form validation
     * (a fail() message reads better than a caught exception mid-submit).
     */
    public function wouldCreateCycle(Lesson $lesson, Lesson $prerequisite): bool
    {
        if ($lesson->is($prerequisite)) {
            return true;
        }

        // A cycle exists iff $prerequisite already (transitively) requires
        // $lesson — walking $prerequisite's own chain and finding $lesson
        // means $lesson -> $prerequisite -> ... -> $lesson.
        return $this->dependsOn($prerequisite, $lesson);
    }

    /**
     * Bounded DFS, capped at the course's own lesson count (design doc
     * decision 6) — corrupt pivot data (a cycle that already slipped in
     * some other way) still can't spin this forever.
     */
    protected function dependsOn(Lesson $start, Lesson $target): bool
    {
        $limit = $this->courseLessonCount($start);
        $visited = [];
        $stack = [$start->id];

        while ($stack !== []) {
            if (count($visited) > $limit) {
                break;
            }

            $currentId = array_pop($stack);

            if (in_array($currentId, $visited, true)) {
                continue;
            }

            $visited[] = $currentId;

            if ($currentId === $target->id) {
                return true;
            }

            $current = Lesson::find($currentId);

            if ($current === null) {
                continue;
            }

            foreach ($current->prerequisites()->pluck('lessons.id') as $prerequisiteId) {
                $stack[] = $prerequisiteId;
            }
        }

        return false;
    }

    protected function courseLessonCount(Lesson $lesson): int
    {
        $courseId = $lesson->section->course_id;

        return Lesson::whereHas('section', fn ($query) => $query->where('course_id', $courseId))->count();
    }
}
