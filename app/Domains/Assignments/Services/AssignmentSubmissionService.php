<?php

namespace App\Domains\Assignments\Services;

use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Exceptions\AssignmentNotAvailableException;
use App\Exceptions\AssignmentResubmissionLimitExceededException;
use App\Exceptions\InvalidFileTypeException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * design doc §2.4 decision 3's versioned-rows shape, in full as of B3: one
 * `submit()` entry point for both the first submission and every
 * resubmission (unlike the plan doc's own "B2 = version 1, B3 adds a
 * separate resubmit action" split) — the two paths differ only in which
 * version_number gets written, computed here from the existing count, so a
 * second method would just duplicate the gate/file-validation/storage logic
 * verbatim. Deviation recorded, not silently taken.
 */
class AssignmentSubmissionService
{
    public const MAX_VERSIONS = 3;

    /**
     * @param  array<int, TemporaryUploadedFile>  $files
     */
    public function submit(Assignment $assignment, User $user, array $files): AssignmentSubmission
    {
        // Re-checked here, not just at the page gate: a Livewire action runs
        // through /livewire/update, which has no domain route constraint and
        // therefore never passed through ShowAssignmentController's gate at
        // all — same Livewire-bypasses-the-route gotcha CLAUDE.md documents,
        // same fix shape QuizAttemptService::start() already applied.
        if (! $assignment->isVisibleTo($user)) {
            throw AssignmentNotAvailableException::forAssignment($assignment->id);
        }

        return $this->submitWithRetry($assignment, $user, $files, allowRetry: true);
    }

    /**
     * Retried once on a genuine race (two clicks/tabs both reading the same
     * existingCount() before either commits): the unique index on
     * (assignment_id, user_id, version_number) is what actually prevents a
     * duplicate version, same "catch the constraint, recompute, recover
     * cleanly" posture BatchEnrolmentService::createEnrolment() already
     * established for an identical count-then-insert race (S4 B3) — found
     * here by this block's own review pass, not by a failing test. Without
     * it, the loser of the race got a raw 500 instead of either a clean
     * success or a real "limit reached" message.
     *
     * The insert itself runs inside its own DB::transaction() — a real
     * SAVEPOINT when nested inside a caller's own transaction (a test's
     * RefreshDatabase wrapper, or any future caller of this service that
     * opens one), not just a bare try/catch. Without it, a Postgres unique-
     * constraint violation poisons the *whole* enclosing transaction
     * (SQLSTATE 25P02) — the retry's own count() query would then fail too,
     * turning "recovered from the race" into "crashed on the recovery
     * attempt instead" (found running this exact test, the identical
     * transaction-poisoning class S10 B11's own review already hit once).
     *
     * @param  array<int, TemporaryUploadedFile>  $files
     */
    protected function submitWithRetry(Assignment $assignment, User $user, array $files, bool $allowRetry): AssignmentSubmission
    {
        $existingCount = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->count();

        if ($existingCount >= self::MAX_VERSIONS) {
            throw AssignmentResubmissionLimitExceededException::forAssignment($assignment->id);
        }

        try {
            return DB::transaction(fn () => $this->storeVersion($assignment, $user, $files, $existingCount + 1));
        } catch (QueryException $e) {
            if (! $allowRetry) {
                throw $e;
            }

            return $this->submitWithRetry($assignment, $user, $files, allowRetry: false);
        }
    }

    /**
     * @param  array<int, TemporaryUploadedFile>  $files
     */
    protected function storeVersion(Assignment $assignment, User $user, array $files, int $versionNumber): AssignmentSubmission
    {
        $storedFiles = [];

        foreach ($files as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());

            // Belt-and-suspenders with the Livewire form's own dynamic
            // 'mimes:' rule (built from this same allowed_file_types column)
            // — defense-in-depth, same posture Lesson::EMBEDDABLE_HOSTS (S9
            // B6) takes toward its own form-level validation.
            if (! in_array($extension, $assignment->allowed_file_types, true)) {
                throw InvalidFileTypeException::forField('files', $extension, $assignment->allowed_file_types);
            }

            $diskPath = $file->storeAs(
                "assignment-submissions/{$assignment->tenant_id}/{$assignment->id}/{$user->id}/{$versionNumber}",
                $file->getClientOriginalName(),
                'local',
            );

            $storedFiles[] = [
                'disk_path' => $diskPath,
                'original_name' => $file->getClientOriginalName(),
                'size_kb' => (int) ceil($file->getSize() / 1024),
            ];
        }

        return AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'version_number' => $versionNumber,
            'files' => $storedFiles,
            'submitted_at' => now(),
            // Computed once, here, and never recomputed later (design doc
            // §2.4 decision) — a due-date edit after submission must not
            // retroactively relabel history.
            'is_late' => now()->gt($assignment->due_at),
            // A resubmission after grading (B4) starts as ungraded again —
            // the *prior* version's row keeps whatever marks/feedback it
            // already has (a different row, never touched here), so grading
            // history is never overwritten, only added to.
        ]);
    }
}
