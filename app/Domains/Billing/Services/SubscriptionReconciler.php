<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Integrations\SubscriptionProvider;
use App\Models\User;
use App\Notifications\GracePeriodReminder;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The only thing that ever turns a Razorpay subscription webhook into a
 * TenantSubscription status change / SubscriptionInvoice row —
 * RazorpaySubscriptionWebhookController does no business logic of its own,
 * same division of responsibility Fees\Services\PaymentReconciler already
 * established for the Orders-API webhook.
 */
class SubscriptionReconciler
{
    /** @var array<int, string> */
    public const HANDLED_EVENTS = [
        'subscription.charged',
        'subscription.completed',
        'payment.failed',
        'subscription.cancelled',
    ];

    /**
     * Signature verified before a single field of the payload is trusted —
     * identical discipline to PaymentReconciler::reconcile(). The local
     * TenantSubscription is looked up by its own razorpay_subscription_id
     * column rather than trusting the payload's notes.tenant_id directly
     * (PaymentReconciler's own approach, necessary there because FeePayment
     * has no equivalent vendor-id column to key off) — a cleaner "does a
     * row we created ourselves actually exist for this id" check.
     */
    public function reconcile(string $payload, string $signature): void
    {
        if (! app(SubscriptionProvider::class)->verifyWebhookSignature($payload, $signature)) {
            throw InvalidWebhookSignatureException::make();
        }

        $data = json_decode($payload, true) ?? [];
        $event = data_get($data, 'event');

        if (! in_array($event, self::HANDLED_EVENTS, true)) {
            return;
        }

        $razorpaySubscriptionId = data_get($data, 'payload.subscription.entity.id');

        $subscription = TenantSubscription::withoutGlobalScope('tenant')
            ->where('razorpay_subscription_id', $razorpaySubscriptionId)
            ->first();

        if ($subscription === null) {
            return;
        }

        app(CurrentTenant::class)->set(Tenant::findOrFail($subscription->tenant_id));

        match ($event) {
            'subscription.charged' => $this->charged($subscription, $data),
            'payment.failed' => $this->failed($subscription, $data),
            // Both mean "no further billing will happen" — 'completed'
            // is Razorpay reaching the subscription's own total_count of
            // cycles (100, ~8 years at a monthly plan — effectively never
            // in practice) rather than an explicit cancel. No separate
            // status exists for that distinction; both collapse to
            // CANCELLED, since every downstream reader (grace/suspension
            // enforcement, the billing views) only needs "still billing or
            // not," not why it stopped.
            'subscription.completed', 'subscription.cancelled' => $this->ended($subscription),
        };
    }

    /**
     * Re-checks under a row lock, same TOCTOU discipline as
     * PaymentReconciler — Razorpay retries webhook delivery on anything
     * but a 2xx response, so the same event can genuinely arrive twice.
     * Deduped on razorpay_payment_id: a resent 'charged' webhook must not
     * write a second SubscriptionInvoice for the same real payment.
     */
    protected function charged(TenantSubscription $subscription, array $data): void
    {
        DB::transaction(function () use ($subscription, $data) {
            $paymentId = data_get($data, 'payload.payment.entity.id');

            if ($paymentId !== null && $this->invoiceAlreadyRecorded($paymentId)) {
                return;
            }

            $locked = TenantSubscription::withoutGlobalScope('tenant')->lockForUpdate()->find($subscription->id);

            $amountPaise = data_get($data, 'payload.payment.entity.amount');
            $amount = $amountPaise !== null ? $amountPaise / 100 : (float) $locked->plan->price_inr_per_month;

            $periodStart = $this->timestampOrFallback(data_get($data, 'payload.subscription.entity.current_start'), $locked->current_period_start);
            $periodEnd = $this->timestampOrFallback(data_get($data, 'payload.subscription.entity.current_end'), $locked->current_period_end);

            $locked->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'grace_ends_at' => null,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            SubscriptionInvoice::withoutEvents(fn () => SubscriptionInvoice::create([
                'tenant_id' => $locked->tenant_id,
                'tenant_subscription_id' => $locked->id,
                'plan_id' => $locked->plan_id,
                'amount' => $amount,
                'kind' => SubscriptionInvoice::KIND_RECURRING,
                'status' => SubscriptionInvoice::STATUS_PAID,
                'razorpay_payment_id' => $paymentId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'paid_at' => now(),
            ]));
        });
    }

    /**
     * Design doc §2.4 decision 7 — flips active -> grace, sets
     * grace_ends_at = +7 days. A *second* failure while already in grace
     * (Razorpay retries the charge itself during the grace window) does
     * not push the deadline further out — `?? ` only sets it when it was
     * null, so the original 7-day clock survives a repeat failure rather
     * than resetting indefinitely.
     *
     * S11 B14 — the grace-entry notice fires only on the genuine
     * active/trialing -> grace transition, captured *before* the update,
     * not on every call that reaches this far — a second distinct failure
     * during an already-open grace window is not a new entry into it, and
     * re-notifying on every one of those would be noise, not a warning.
     * Sent after the transaction commits, not inside it — a notification
     * tied to a write that could still be rolled back by something later
     * in the same request has nothing to roll back to.
     */
    protected function failed(TenantSubscription $subscription, array $data): void
    {
        $justEnteredGrace = DB::transaction(function () use ($subscription, $data): bool {
            $locked = TenantSubscription::withoutGlobalScope('tenant')->lockForUpdate()->find($subscription->id);

            if ($locked->status === TenantSubscription::STATUS_CANCELLED) {
                return false;
            }

            $paymentId = data_get($data, 'payload.payment.entity.id');

            if ($paymentId !== null && $this->invoiceAlreadyRecorded($paymentId)) {
                return false;
            }

            $wasAlreadyInGrace = $locked->status === TenantSubscription::STATUS_GRACE;

            $locked->update([
                'status' => TenantSubscription::STATUS_GRACE,
                'grace_ends_at' => $locked->grace_ends_at ?? now()->addDays(7),
            ]);

            SubscriptionInvoice::withoutEvents(fn () => SubscriptionInvoice::create([
                'tenant_id' => $locked->tenant_id,
                'tenant_subscription_id' => $locked->id,
                'plan_id' => $locked->plan_id,
                'amount' => (float) $locked->plan->price_inr_per_month,
                'kind' => SubscriptionInvoice::KIND_RECURRING,
                'status' => SubscriptionInvoice::STATUS_FAILED,
                'razorpay_payment_id' => $paymentId,
                'period_start' => $locked->current_period_start,
                'period_end' => $locked->current_period_end,
            ]));

            return ! $wasAlreadyInGrace;
        });

        if ($justEnteredGrace) {
            $this->notifyInstituteAdmins($subscription->fresh(), 'entered_grace');
        }
    }

    protected function notifyInstituteAdmins(TenantSubscription $subscription, string $stage): void
    {
        $admins = User::where('tenant_id', $subscription->tenant_id)
            ->role('institute_admin')
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new GracePeriodReminder($subscription, $stage));
        }
    }

    protected function ended(TenantSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $locked = TenantSubscription::withoutGlobalScope('tenant')->lockForUpdate()->find($subscription->id);

            if ($locked->status === TenantSubscription::STATUS_CANCELLED) {
                return;
            }

            $locked->update([
                'status' => TenantSubscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
        });
    }

    protected function invoiceAlreadyRecorded(string $paymentId): bool
    {
        return SubscriptionInvoice::withoutGlobalScope('tenant')
            ->where('razorpay_payment_id', $paymentId)
            ->exists();
    }

    protected function timestampOrFallback(?int $unixTimestamp, Carbon $fallback): Carbon
    {
        return $unixTimestamp !== null ? Carbon::createFromTimestamp($unixTimestamp) : $fallback;
    }
}
