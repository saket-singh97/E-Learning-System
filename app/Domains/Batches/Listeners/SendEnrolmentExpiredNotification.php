<?php

namespace App\Domains\Batches\Listeners;

use App\Domains\Batches\Events\EnrolmentApprovalExpired;
use App\Domains\Batches\Models\Enrolment;
use App\Notifications\EnrolmentRequestExpired;

class SendEnrolmentExpiredNotification
{
    public function handle(EnrolmentApprovalExpired $event): void
    {
        $enrolment = Enrolment::find($event->enrolmentId);

        $enrolment?->user?->notify(new EnrolmentRequestExpired($enrolment));
    }
}
