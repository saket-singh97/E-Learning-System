<?php

namespace App\Domains\Fees\Listeners;

use App\Domains\Batches\Events\EnrolmentAwaitingPayment;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Fees\Services\FeeInvoiceService;

/**
 * Fees' half of the one-way-ignorance shape design doc §2.4 decision 3
 * describes — Batches dispatches EnrolmentAwaitingPayment with no idea Fees
 * exists; this is the only thing that turns it into one or more real
 * FeeInvoice rows (one per instalment, once B4's Batch.instalment_plan
 * template applies — invisible from here, invoicesFor() owns that entirely).
 */
class CreateInvoiceForPendingEnrolment
{
    public function handle(EnrolmentAwaitingPayment $event): void
    {
        $enrolment = Enrolment::find($event->enrolmentId);

        if ($enrolment === null) {
            return;
        }

        app(FeeInvoiceService::class)->invoicesFor($enrolment);
    }
}
