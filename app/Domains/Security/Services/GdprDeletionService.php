<?php

namespace App\Domains\Security\Services;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Security\Models\GdprDeletionRequest;
use App\Exceptions\GdprDeletionRequestNotPendingException;
use App\Models\User;
use Throwable;

/**
 * Design doc §4 decision 8 — anonymize, not hard-delete, anything a
 * financial/legal record still points at; hard-delete anything that
 * doesn't. `FeeInvoice`/`SubscriptionInvoice` carry no PII columns of
 * their own (confirmed by reading their migrations, not assumed) — the
 * PII decision 8 actually names (`name`, `email`, `phone`) lives on the
 * `User` row itself, so anonymizing that row is what protects those
 * retained financial rows without deleting them: they keep pointing at a
 * real `user_id`, just one stripped of identity. `QuizAttempt` and
 * `VideoWatchEvent` are decision 8's own named examples with no
 * retention requirement, hard-deleted outright.
 *
 * Not exhaustive across every domain (`Enrolment`, `Certificate`,
 * `AssignmentSubmission`, this slice's own `LoginHistory`/`DeviceSession`)
 * — same "smallest version that delivers the block's value, not an
 * exhaustive sweep" posture B8's own export job already took; a real
 * fast-follow if a genuine compliance audit demands more.
 */
class GdprDeletionService
{
    public function request(User $subject, User $requestedBy): GdprDeletionRequest
    {
        return GdprDeletionRequest::create([
            'tenant_id' => $subject->tenant_id,
            'user_id' => $subject->id,
            'requested_by_id' => $requestedBy->id,
            'status' => GdprDeletionRequest::STATUS_PENDING,
            'scheduled_for' => now()->addDays((int) config('security.gdpr_deletion_grace_days')),
        ]);
    }

    /**
     * Decision 8's own "a grace window to cancel" — a pending request is
     * simply removed, undoing the schedule; there's no `cancelled` status
     * in B0's own schema; once already `processing`/`completed`/`failed`
     * there is nothing left to cancel.
     */
    public function cancel(GdprDeletionRequest $request): void
    {
        if ($request->status !== GdprDeletionRequest::STATUS_PENDING) {
            throw GdprDeletionRequestNotPendingException::forRequest($request->id);
        }

        $request->delete();
    }

    public function execute(GdprDeletionRequest $request): void
    {
        $request->update(['status' => GdprDeletionRequest::STATUS_PROCESSING]);

        try {
            $user = User::withTrashed()->findOrFail($request->user_id);

            $user->update([
                'name' => 'Deleted User',
                'email' => "deleted-user-{$user->id}@deleted.invalid",
                'phone' => null,
            ]);

            QuizAttempt::where('user_id', $user->id)->delete();
            VideoWatchEvent::where('user_id', $user->id)->delete();

            $request->update([
                'status' => GdprDeletionRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $request->update(['status' => GdprDeletionRequest::STATUS_FAILED]);

            throw $e;
        }
    }
}
