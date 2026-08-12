<?php

namespace App\Domains\Batches\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by both of S5's attendance writers (RecordVideoCoverageAttendance,
 * BatchSessionsRelationManager's markAttendance action) — no event fired on
 * an AttendanceRecord write before this block (S8 B3 confirmed by reading
 * both writers; design doc's own "S5's three attendance writers" wording is
 * stale, only two ever shipped, the third — live-webhook — is fast-follow).
 * One-way-ignorance shape (primitive ids, not model instances), same as
 * EnrolmentAwaitingPayment/PaymentCaptured: Comms listens for this without
 * Batches knowing Comms exists.
 */
class AttendanceRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
        public readonly string $status,
    ) {}
}
