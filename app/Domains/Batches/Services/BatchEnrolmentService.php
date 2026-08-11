<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Events\EnrolmentAwaitingPayment;
use App\Domains\Batches\Events\SeatReleased;
use App\Domains\Batches\Events\StudentEnrolled;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Exceptions\BatchFullException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single answer to "can this student enrol, and what happens when they
 * do" — same centralising principle CLAUDE.md documents for LessonAccess.
 * BatchCatalog (Livewire, B7), EnrolmentsRelationManager (Filament), and the
 * approval-expiry job (B5) all call this service; none of them re-implement
 * seat counting or status transitions themselves.
 */
class BatchEnrolmentService
{
    /**
     * Student-initiated self-enrolment. Only reachable for the two
     * self-service modes — a manual_only batch has no student-facing entry
     * point, that path is addManually() below, staff-only.
     */
    public function enrol(User $student, Batch $batch): Enrolment
    {
        if ($existing = $this->existingEnrolment($batch, $student)) {
            return $existing;
        }

        if (! $this->isOpenForEnrolment($batch)) {
            throw new RuntimeException("Batch [{$batch->id}] is not open for enrolment.");
        }

        // Pulled out of the match's default arm (S7 B1) so a paid batch gets
        // the same manual_only refusal a free one already does, without
        // duplicating the check in enrolPendingPayment() below.
        if ($batch->self_enrol_mode === 'manual_only') {
            throw new RuntimeException("Batch [{$batch->id}] does not allow self-enrolment.");
        }

        if ($batch->is_paid) {
            return $this->enrolPendingPayment($student, $batch);
        }

        return match ($batch->self_enrol_mode) {
            'self_enrol_direct' => $this->enrolDirect($student, $batch),
            'self_enrol_approval' => $this->createEnrolment($student, $batch, [
                'status' => 'pending_approval',
                'source' => 'self_enrol_approval',
                'requested_at' => now(),
                'expires_at' => now()->addDays(7),
            ]),
            default => throw new RuntimeException("Batch [{$batch->id}] does not allow self-enrolment."),
        };
    }

    /**
     * A paid batch's enrol() attempt never resolves straight to
     * active/waitlisted/pending_approval — it lands pending_payment and
     * stays there until Fees confirms a captured payment (design doc §2.4
     * decision 2). `source` still records which self_enrol_mode this would
     * have resolved through, so the payment-capture listener (B2) knows
     * whether to finalize into 'active' (direct) or 'pending_approval'
     * (approval) once payment lands. Wrapped in one transaction so a
     * listener failure can't strand an enrolment with no invoice to pay —
     * EnrolmentAwaitingPayment's sync listeners run inside it, not after.
     */
    protected function enrolPendingPayment(User $student, Batch $batch): Enrolment
    {
        return DB::transaction(function () use ($student, $batch) {
            $enrolment = $this->createEnrolment($student, $batch, [
                'status' => 'pending_payment',
                'source' => $batch->self_enrol_mode,
                'requested_at' => now(),
            ]);

            EnrolmentAwaitingPayment::dispatch($enrolment->id, $enrolment->tenant_id);

            return $enrolment;
        });
    }

    /**
     * The only thing that ever resolves a pending_payment enrolment onward
     * — called by Batches' own PaymentCaptured listener (S7 B2) once Fees
     * confirms a captured payment, never called directly by Fees itself
     * (CLAUDE.md: domains talk through Events only). `source` (set by
     * enrolPendingPayment() above) says which self_enrol_mode this should
     * now resolve into. A status other than pending_payment is a no-op, so
     * a duplicate/late event can never re-finalize an already-settled
     * enrolment.
     */
    public function finalizePendingPayment(Enrolment $enrolment): Enrolment
    {
        if ($enrolment->status !== 'pending_payment') {
            return $enrolment;
        }

        return match ($enrolment->source) {
            'self_enrol_direct' => $this->finalizeDirect($enrolment),
            'self_enrol_approval' => tap($enrolment)->update([
                'status' => 'pending_approval',
                'expires_at' => now()->addDays(7),
            ]),
            default => $enrolment,
        };
    }

    /**
     * Same seat-race protection enrolDirect() itself uses
     * (lockForUpdate() on the batch, resolveDirectStatus() decides active
     * vs waitlisted) — this updates an already-existing row rather than
     * creating one, so it can't literally call enrolDirect(), but shares
     * its seat-decision logic rather than re-deriving it.
     */
    protected function finalizeDirect(Enrolment $enrolment): Enrolment
    {
        return DB::transaction(function () use ($enrolment) {
            $locked = Batch::whereKey($enrolment->batch_id)->lockForUpdate()->firstOrFail();
            $status = $this->resolveDirectStatus($locked);

            $enrolment->update(['status' => $status]);

            if ($status === 'active') {
                StudentEnrolled::dispatch($enrolment->id, $enrolment->tenant_id);
            }

            return $enrolment;
        });
    }

    /**
     * Staff-only path via EnrolmentsRelationManager's "Add student" action —
     * lands active immediately, regardless of the batch's self_enrol_mode.
     */
    public function addManually(Batch $batch, User $student, User $actor): Enrolment
    {
        if ($existing = $this->existingEnrolment($batch, $student)) {
            return $existing;
        }

        return $this->createEnrolment($student, $batch, [
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
            'decided_at' => now(),
            'decided_by' => $actor->id,
        ]);
    }

    /**
     * lockForUpdate() on the batch row for the same reason enrolDirect()
     * takes it — staff can have several pending_approval requests open for
     * the same near-full batch, and approving them one after another (or two
     * staff approving concurrently) must not push active count past
     * seat_limit just because self_enrol_direct's race guard doesn't cover
     * this path (found in the B8 review pass — no prior test exercised
     * approve() against a capped batch).
     */
    public function approve(Enrolment $enrolment, User $actor): Enrolment
    {
        return DB::transaction(function () use ($enrolment, $actor) {
            $batch = Batch::whereKey($enrolment->batch_id)->lockForUpdate()->firstOrFail();

            if ($batch->seat_limit !== null && $this->activeCount($batch) >= $batch->seat_limit) {
                throw BatchFullException::forBatch($batch->id);
            }

            $enrolment->update([
                'status' => 'active',
                'decided_at' => now(),
                'decided_by' => $actor->id,
            ]);

            StudentEnrolled::dispatch($enrolment->id, $enrolment->tenant_id);

            return $enrolment;
        });
    }

    public function reject(Enrolment $enrolment, User $actor): Enrolment
    {
        $enrolment->update([
            'status' => 'rejected',
            'decided_at' => now(),
            'decided_by' => $actor->id,
        ]);

        return $enrolment;
    }

    /**
     * A seat is only actually freed when the cancelled enrolment was active
     * — cancelling a waitlisted or pending_approval row doesn't change the
     * active count, so it must not trigger a promotion (design doc §3.2:
     * promotion is triggered by "anything that reduces active enrolment
     * count", not by cancellation itself).
     *
     * The active branch takes the same lockForUpdate() transaction
     * enrolDirect()/approve() do — promoting a waitlisted student is still a
     * seat-count mutation, and without the lock it can race enrolDirect() or
     * approve() for the very seat this cancellation just freed, or race a
     * second concurrent cancel() promoting the same waitlisted row twice
     * (found in the post-S4 review pass — this was the one seat-mutating
     * path left unlocked).
     */
    public function cancel(Enrolment $enrolment): Enrolment
    {
        if ($enrolment->status !== 'active') {
            $enrolment->update(['status' => 'cancelled']);

            return $enrolment;
        }

        return DB::transaction(function () use ($enrolment) {
            Batch::whereKey($enrolment->batch_id)->lockForUpdate()->firstOrFail();

            $enrolment->update(['status' => 'cancelled']);

            SeatReleased::dispatch($enrolment->id, $enrolment->tenant_id);
            $this->promoteNextWaitlisted($enrolment->batch);

            return $enrolment;
        });
    }

    /**
     * Only called after cancel() confirms a seat was actually freed, so
     * exactly one promotion is correct here without re-checking seat_limit
     * (design doc §3.2). FIFO via created_at, with id as a deterministic
     * tiebreak — enrolments.created_at is timestamp(0) in Postgres (whole
     * seconds, verified against information_schema), so two joins landing in
     * the same second tie on created_at alone; id is strictly increasing at
     * insert time and actually preserves arrival order for those ties.
     */
    protected function promoteNextWaitlisted(Batch $batch): void
    {
        $next = Enrolment::where('batch_id', $batch->id)
            ->where('status', 'waitlisted')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            return;
        }

        $next->update(['status' => 'active']);

        StudentEnrolled::dispatch($next->id, $next->tenant_id);
    }

    public function seatsRemaining(Batch $batch): ?int
    {
        if ($batch->seat_limit === null) {
            return null;
        }

        return max(0, $batch->seat_limit - $this->activeCount($batch));
    }

    public function canEnrol(User $student, Batch $batch): bool
    {
        if (! $this->isOpenForEnrolment($batch)) {
            return false;
        }

        return $this->existingEnrolment($batch, $student) === null;
    }

    /**
     * enrol()'s own gate, not just canEnrol()'s advisory check — BatchCatalog
     * ::join() is a directly-callable Livewire method with no role gate on
     * /batches, so a draft-batch or unpublished-course enrolment attempt has
     * to fail inside the mutator itself, not merely be hidden by the
     * catalog's display query (found in the post-S4 review pass: enrol()
     * trusted callers to check canEnrol() first, but nothing enforced that).
     */
    protected function isOpenForEnrolment(Batch $batch): bool
    {
        return $batch->status === 'published' && $batch->course?->status === 'published';
    }

    /**
     * lockForUpdate() on the batch row so two students racing for the last
     * seat can't both read the same seats_taken count and both land active
     * — the loser gets waitlisted instead of a double-booked seat (design
     * doc §3.1, same race class the S2 review found in
     * BandwidthUsageDaily::record()).
     */
    protected function enrolDirect(User $student, Batch $batch): Enrolment
    {
        return DB::transaction(function () use ($student, $batch) {
            $locked = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $status = $this->resolveDirectStatus($locked);

            $enrolment = $this->createEnrolment($student, $locked, [
                'status' => $status,
                'source' => 'self_enrol_direct',
                'requested_at' => now(),
            ]);

            if ($status === 'active') {
                StudentEnrolled::dispatch($enrolment->id, $enrolment->tenant_id);
            }

            return $enrolment;
        });
    }

    /**
     * Shared by enrolDirect() (create) and finalizeDirect() (update of an
     * already-existing pending_payment row, S7 B2) — same seat-vs-waitlist
     * decision either way, expected to run inside a transaction that
     * already holds the batch row's lock.
     */
    protected function resolveDirectStatus(Batch $lockedBatch): string
    {
        return $lockedBatch->seat_limit === null || $this->activeCount($lockedBatch) < $lockedBatch->seat_limit
            ? 'active'
            : 'waitlisted';
    }

    /**
     * Duplicate enrolment attempts hit the partial unique index on
     * (tenant_id, batch_id, user_id) — caught here so the caller gets the
     * existing row's status back instead of a raw DB error (design doc §4).
     */
    protected function createEnrolment(User $student, Batch $batch, array $attributes): Enrolment
    {
        try {
            return Enrolment::create(array_merge([
                'batch_id' => $batch->id,
                'user_id' => $student->id,
            ], $attributes));
        } catch (QueryException $e) {
            if ($existing = $this->existingEnrolment($batch, $student)) {
                return $existing;
            }

            throw $e;
        }
    }

    protected function existingEnrolment(Batch $batch, User $student): ?Enrolment
    {
        return Enrolment::where('batch_id', $batch->id)->where('user_id', $student->id)->first();
    }

    protected function activeCount(Batch $batch): int
    {
        return Enrolment::where('batch_id', $batch->id)->where('status', 'active')->count();
    }
}
