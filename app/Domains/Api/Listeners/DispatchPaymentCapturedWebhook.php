<?php

namespace App\Domains\Api\Listeners;

use App\Domains\Api\Services\WebhookDispatcher;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Tenancy\Models\Tenant;

/**
 * S21 B5 — the first real webhook trigger (design doc §4 decision 3 says
 * "an event fires", plan's own B5 checklist asks to confirm which one
 * before starting): a captured payment is the clearest "an institute's own
 * external system wants to know about this" moment already in the app —
 * same one-way-ignorance shape every other PaymentCaptured listener here
 * already uses, Api has no idea Fees exists beyond this event's shape.
 */
class DispatchPaymentCapturedWebhook
{
    public function handle(PaymentCaptured $event): void
    {
        $tenant = Tenant::find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', [
            'enrolment_id' => $event->enrolmentId,
        ]);
    }
}
