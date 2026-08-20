<?php

namespace App\Domains\Fees\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fees' half of the same one-way-ignorance shape PaymentCaptured already
 * established — carries a fee_invoice id, not a FeeInvoice model or a
 * direct call into Content's own ProgramEnrolmentService, so the Content
 * listener is the only thing that ever knows that service exists.
 *
 * S13 B17's own review pass found PaymentReconciler calling
 * ProgramEnrolmentService directly (CLAUDE.md: "Domains talk to each
 * other through Events/ only, never by calling into another domain's
 * services directly") — this event is the fix.
 */
class ProgramInvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $feeInvoiceId,
        public readonly int $tenantId,
    ) {}
}
