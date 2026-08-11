<?php

namespace App\Domains\Fees\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fees' half of the same one-way-ignorance shape EnrolmentAwaitingPayment
 * (Batches, S7 B1) established the other direction — carries an enrolment
 * id, not a FeeInvoice, so the Batches listener never needs to know Fees'
 * own models exist.
 */
class PaymentCaptured
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $tenantId,
    ) {}
}
