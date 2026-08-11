<?php

namespace App\Domains\Batches\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid batch's enrol() attempt lands here instead of resolving straight to
 * active/waitlisted/pending_approval (S7 design doc §2.4 decision 2).
 * Batches doesn't call into Fees directly to create the invoice — Fees
 * listens for this instead (CLAUDE.md: domains talk through Events only),
 * the same one-way-ignorance shape decision 3 already established for the
 * reverse direction (Fees -> Batches on payment capture).
 */
class EnrolmentAwaitingPayment
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $tenantId,
    ) {}
}
