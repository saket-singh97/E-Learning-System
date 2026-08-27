<?php

namespace App\Domains\Fees\Services;

use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Events\ProgramInvoicePaid;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Integrations\PaymentProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that ever turns a Razorpay webhook into a captured
 * FeePayment / paid FeeInvoice — RazorpayWebhookController does no
 * business logic of its own, just extracts the raw payload/signature and
 * hands them here (design doc §3.0).
 */
class PaymentReconciler
{
    /**
     * Signature is verified before a single field of the payload is
     * trusted for anything — including which tenant to bind. An
     * unverified payload's own notes.tenant_id must never be trusted
     * enough to bind CurrentTenant, let alone write a row.
     */
    public function reconcile(string $payload, string $signature): void
    {
        if (! app(PaymentProvider::class)->verifyWebhookSignature($payload, $signature)) {
            throw InvalidWebhookSignatureException::make();
        }

        $data = json_decode($payload, true) ?? [];
        $event = data_get($data, 'event');

        if (! in_array($event, ['payment.captured', 'payment.failed'], true)) {
            return;
        }

        $tenant = Tenant::find((int) data_get($data, 'payload.order.entity.notes.tenant_id'));

        if ($tenant === null) {
            return;
        }

        app(CurrentTenant::class)->set($tenant);

        $orderId = data_get($data, 'payload.order.entity.id');

        match ($event) {
            'payment.captured' => $this->capture($orderId, data_get($data, 'payload.payment.entity.id')),
            'payment.failed' => $this->fail($orderId, data_get($data, 'payload.payment.entity.error_description')),
        };
    }

    /**
     * Re-checks status under a row lock before writing — Razorpay itself
     * retries webhook delivery on anything but a 2xx response, so the same
     * payload can genuinely arrive twice. An already-captured row is left
     * alone rather than re-processed (idempotent no-op), same TOCTOU
     * discipline as every scheduled sweep in this app.
     */
    protected function capture(?string $orderId, ?string $providerPaymentId): void
    {
        DB::transaction(function () use ($orderId, $providerPaymentId) {
            $payment = FeePayment::where('provider_order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status === 'captured') {
                return;
            }

            $payment->update([
                'status' => 'captured',
                'provider_payment_id' => $providerPaymentId,
                'webhook_verified_at' => now(),
            ]);

            $invoice = $payment->invoice;
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);

            // A membership invoice activates its own StudentSubscription
            // directly (S20 B4) — both classes live in Fees, so unlike the
            // program/enrolment branches below this is a same-domain call,
            // not an event; CLAUDE.md's "events only" rule is about
            // crossing a domain boundary, and this never does.
            //
            // A program-linked invoice has no single enrolment_id to carry
            // (S13 B8, design doc decision 4), so it can't reuse
            // PaymentCaptured's shape — but this still dispatches an
            // event, not a direct call into Content's own
            // ProgramEnrolmentService (fixed by B17's own review pass,
            // which caught the original direct-call version of this line
            // as a CLAUDE.md violation: "Domains talk to each other
            // through Events/ only").
            if ($invoice->student_membership_plan_id !== null) {
                app(StudentSubscriptionService::class)->activateFromPayment($invoice->fresh());
            } elseif ($invoice->program_id !== null) {
                ProgramInvoicePaid::dispatch($invoice->id, $invoice->tenant_id);
            } else {
                PaymentCaptured::dispatch($invoice->enrolment_id, $invoice->tenant_id);
            }
        });
    }

    /**
     * A failed attempt never touches the FeeInvoice — it stays 'pending',
     * so FeeCheckout's own "Try again" path (B1) can create a fresh
     * FeePayment/order against the same invoice (design doc's own
     * retry-on-failure requirement, P5).
     */
    protected function fail(?string $orderId, ?string $reason): void
    {
        DB::transaction(function () use ($orderId, $reason) {
            $payment = FeePayment::where('provider_order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status !== 'created') {
                return;
            }

            $payment->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'webhook_verified_at' => now(),
            ]);
        });
    }
}
