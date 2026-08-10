<?php

namespace App\Domains\Batches\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EnrolmentApprovalExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $tenantId,
    ) {}
}
