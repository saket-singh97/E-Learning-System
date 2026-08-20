<?php

namespace App\Domains\Content\Listeners;

use App\Domains\Content\Services\ProgramEnrolmentService;
use App\Domains\Fees\Events\ProgramInvoicePaid;
use App\Domains\Fees\Models\FeeInvoice;

/**
 * Content's half of the same one-way-ignorance shape
 * FinalizeEnrolmentOnPaymentCaptured (Batches) already established for
 * PaymentCaptured — Fees dispatches with no idea Content, or
 * ProgramEnrolmentService, exists.
 */
class EnrolFromProgramPayment
{
    public function handle(ProgramInvoicePaid $event): void
    {
        $invoice = FeeInvoice::find($event->feeInvoiceId);

        if ($invoice === null) {
            return;
        }

        app(ProgramEnrolmentService::class)->enrolFromInvoice($invoice);
    }
}
