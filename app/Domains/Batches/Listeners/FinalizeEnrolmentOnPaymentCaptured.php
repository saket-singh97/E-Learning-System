<?php

namespace App\Domains\Batches\Listeners;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Fees\Events\PaymentCaptured;

/**
 * Batches' half of the one-way-ignorance shape S7 design doc §2.4
 * decision 3 describes — Fees dispatches PaymentCaptured with no idea
 * Batches exists; this is the only thing that turns it into a real
 * enrolment-status change.
 */
class FinalizeEnrolmentOnPaymentCaptured
{
    public function handle(PaymentCaptured $event): void
    {
        $enrolment = Enrolment::find($event->enrolmentId);

        if ($enrolment === null) {
            return;
        }

        app(BatchEnrolmentService::class)->finalizePendingPayment($enrolment);
    }
}
