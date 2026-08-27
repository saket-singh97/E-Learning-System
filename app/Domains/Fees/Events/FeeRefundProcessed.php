<?php

namespace App\Domains\Fees\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fees' half of the same one-way-ignorance shape `ProgramInvoicePaid`
 * already established — carries a fee_payment id, not a `FeeRefund` model
 * or a direct call into Growth's own `AffiliateReferral` reversal (design
 * doc §4 decision 6, CLAUDE.md: "Domains talk to each other through
 * Events/ only"). Fees dispatches this with no idea an affiliate program
 * exists at all.
 */
class FeeRefundProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $feePaymentId,
        public readonly int $tenantId,
    ) {}
}
