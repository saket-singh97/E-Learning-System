<?php

namespace App\Domains\Content\Services;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Models\User;

/**
 * Per-lesson-type binary completion heuristic (design doc decision 5) — not
 * a unified progress percentage. No existing "is this lesson complete"
 * concept spans all 4+ lesson types today; building a real weighted
 * progress engine is a bigger, separate item. A deliberate simplification,
 * documented as such, not silently approximate.
 */
class LessonCompletion
{
    public function isComplete(Lesson $lesson, User $viewer): bool
    {
        return match ($lesson->type) {
            'video' => $this->watchedThreshold($lesson, $viewer),
            'quiz' => $this->attemptedQuiz($lesson, $viewer),
            'assignment' => $this->submittedAssignment($lesson, $viewer),
            // pdf/text/embed/live_session — nothing to "finish" the way a
            // video has a duration or a quiz has a submit button, so the
            // heuristic is presence-based: any recorded engagement at all.
            // Design doc decision 5 names only "text/live_session" for this
            // bucket; pdf/embed share the same "nothing to measure
            // completion of" reasoning, so they fall here too rather than
            // being left with no heuristic at all.
            default => $this->hasAnyEngagement($lesson, $viewer),
        };
    }

    protected function watchedThreshold(Lesson $lesson, User $viewer): bool
    {
        if (blank($lesson->video_duration_seconds)) {
            return false;
        }

        $thresholdSeconds = $lesson->video_duration_seconds * (config('content.lesson_completion_watch_threshold') / 100);

        return VideoWatchEvent::where('lesson_id', $lesson->id)
            ->where('user_id', $viewer->id)
            ->where('end_s', '>=', $thresholdSeconds)
            ->exists();
    }

    protected function attemptedQuiz(Lesson $lesson, User $viewer): bool
    {
        $quizId = Quiz::where('lesson_id', $lesson->id)->value('id');

        if ($quizId === null) {
            return false;
        }

        return QuizAttempt::where('quiz_id', $quizId)
            ->where('user_id', $viewer->id)
            ->where('status', '!=', 'in_progress')
            ->exists();
    }

    protected function submittedAssignment(Lesson $lesson, User $viewer): bool
    {
        $assignmentId = Assignment::where('lesson_id', $lesson->id)->value('id');

        if ($assignmentId === null) {
            return false;
        }

        return AssignmentSubmission::where('assignment_id', $assignmentId)
            ->where('user_id', $viewer->id)
            ->exists();
    }

    protected function hasAnyEngagement(Lesson $lesson, User $viewer): bool
    {
        $hasWatchEvent = VideoWatchEvent::where('lesson_id', $lesson->id)
            ->where('user_id', $viewer->id)
            ->exists();

        if ($hasWatchEvent) {
            return true;
        }

        return AttendanceRecord::where('attendable_type', Lesson::class)
            ->where('attendable_id', $lesson->id)
            ->where('user_id', $viewer->id)
            ->exists();
    }
}
