<?php

namespace App\Domains\Assignments\Services;

use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Exceptions\UnknownRubricCriterionException;
use App\Models\User;

/**
 * Marks-only vs rubric grading (design doc §2.4 decision 2) coexist
 * per-assignment, decided here by whether Assignment.rubric is blank — not
 * a tenant-wide toggle, not decided by the caller.
 */
class AssignmentGradingService
{
    /**
     * @param  array<string, float>|null  $rubricScores  Keyed by the
     *     assignment's own rubric[].id, points awarded per criterion.
     */
    public function grade(
        AssignmentSubmission $submission,
        User $grader,
        ?float $marksAwarded,
        ?string $feedback,
        ?array $rubricScores,
    ): void {
        $assignment = $submission->assignment;
        $rubric = $assignment->rubric;

        if (blank($rubric)) {
            $submission->update([
                'marks_awarded' => $marksAwarded,
                'feedback' => $feedback,
                'rubric_scores' => null,
                'graded_by_user_id' => $grader->id,
                'graded_at' => now(),
            ]);

            return;
        }

        $validCriterionIds = collect($rubric)->pluck('id')->all();

        foreach (array_keys($rubricScores ?? []) as $criterionId) {
            if (! in_array($criterionId, $validCriterionIds, true)) {
                throw UnknownRubricCriterionException::forAssignment($assignment->id, (string) $criterionId);
            }
        }

        // marks_awarded is derived from rubric_scores, not accepted as a
        // separate manually-typed number — a grader could otherwise submit
        // a total that disagrees with its own criterion breakdown.
        $submission->update([
            'marks_awarded' => array_sum($rubricScores ?? []),
            'feedback' => $feedback,
            'rubric_scores' => $rubricScores,
            'graded_by_user_id' => $grader->id,
            'graded_at' => now(),
        ]);
    }
}
