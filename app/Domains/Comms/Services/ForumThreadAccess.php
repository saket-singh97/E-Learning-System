<?php

namespace App\Domains\Comms\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ForumThread;
use App\Models\User;

/**
 * S19 N14 pt.2 — the one place answering "may this viewer participate in
 * this forum thread", same "lesson access rules live in one class"
 * discipline `LessonAccess` already established (CLAUDE.md) — `BatchForum`
 * and the `forum-thread.{id}` channel authorization callback (B3) both need
 * the identical batch-enrolment rule, and duplicating it in both places
 * risks exactly the kind of drift that discipline exists to prevent.
 */
class ForumThreadAccess
{
    public static function canParticipateInBatch(Batch $batch, ?User $viewer): bool
    {
        if ($viewer === null || $viewer->tenant_id !== $batch->tenant_id) {
            return false;
        }

        if ($viewer->hasRole('institute_admin')) {
            return true;
        }

        if ($viewer->id === $batch->instructor_id) {
            return true;
        }

        return Enrolment::where('batch_id', $batch->id)
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * A null `batch_id` (S19 N14) is a general, institute-wide thread — any
     * tenant user, same reach `GeneralForum` already gives it.
     */
    public static function canParticipateInThread(ForumThread $thread, ?User $viewer): bool
    {
        if ($viewer === null || $viewer->tenant_id !== $thread->tenant_id) {
            return false;
        }

        if ($thread->batch_id === null) {
            return true;
        }

        $batch = $thread->batch;

        return $batch !== null && self::canParticipateInBatch($batch, $viewer);
    }
}
