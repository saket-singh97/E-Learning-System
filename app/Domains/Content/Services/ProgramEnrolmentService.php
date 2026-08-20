<?php

namespace App\Domains\Content\Services;

use App\Domains\Batches\Events\StudentEnrolled;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Program;
use App\Domains\Fees\Models\FeeInvoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The payment-success half of a program purchase (design doc §3.1/decision
 * 4/decision 3) — PaymentReconciler calls this once a program-linked
 * FeeInvoice is captured. One Enrolment per bundled batch, landed 'active'
 * immediately: no seat-limit/waitlist check, mirroring
 * BatchEnrolmentService::addManually()'s own "lands active immediately"
 * shape (decision 3's own "no new resolution logic needed") — the sale is
 * already confirmed by the captured payment, unlike a self-enrol request
 * that still has to compete for a seat.
 */
class ProgramEnrolmentService
{
    /**
     * Idempotent per batch: Razorpay retries webhook delivery on anything
     * but a 2xx response, so the same payload can arrive twice
     * (PaymentReconciler's own docblock) — and, unlike a single-batch
     * purchase, this loop's own two concurrent deliveries could each
     * pass the same batch's exists() check before either commits. Same
     * TOCTOU BatchEnrolmentService::createEnrolment() already guards
     * against on the identical (tenant_id, batch_id, user_id) partial
     * unique index (S13 B17 review pass — the exists()-then-create shape
     * here had no such guard, so a genuine race threw a raw
     * QueryException instead of resolving cleanly).
     */
    public function enrolFromInvoice(FeeInvoice $invoice): void
    {
        $program = Program::withTrashed()->findOrFail($invoice->program_id);
        $user = User::findOrFail($invoice->user_id);

        foreach ($program->batches as $batch) {
            DB::transaction(function () use ($batch, $user) {
                if (Enrolment::where('batch_id', $batch->id)->where('user_id', $user->id)->exists()) {
                    return;
                }

                try {
                    $enrolment = Enrolment::create([
                        'batch_id' => $batch->id,
                        'user_id' => $user->id,
                        'status' => 'active',
                        'source' => 'program_purchase',
                        'requested_at' => now(),
                        'decided_at' => now(),
                    ]);
                } catch (QueryException) {
                    // Lost the race to a concurrent delivery of the same
                    // webhook — the other one already created the row.
                    return;
                }

                StudentEnrolled::dispatch($enrolment->id, $enrolment->tenant_id);
            });
        }
    }
}
