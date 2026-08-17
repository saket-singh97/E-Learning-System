<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\PlanDowngradeBlockedException;
use App\Exceptions\TenantAlreadySubscribedException;
use App\Exceptions\TenantNotSubscribedException;
use App\Integrations\SubscriptionProvider;
use Illuminate\Support\Facades\DB;

class PlanChangeService
{
    public function __construct(
        private readonly SubscriptionProvider $provider,
        private readonly SeatQuota $seatQuota,
        private readonly StorageQuota $storageQuota,
    ) {}

    /**
     * First-time subscription only — a tenant with an already-active/
     * trialing/grace subscription must go through B10's changePlan()
     * instead, which also handles proration; this method has no concept
     * of "there was a previous plan" to prorate from.
     *
     * Runs from the Super panel, where CurrentTenant is never bound to
     * the tenant being subscribed (a super_admin has no tenant context of
     * their own) — same "withoutEvents + explicit tenant_id" shape every
     * other BelongsToTenant row created from an unscoped position already
     * uses in this codebase (CertificateService, RecordVideoCoverageAttendance).
     */
    public function subscribe(Tenant $tenant, Plan $plan): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $plan): TenantSubscription {
            // S11 B15 — closes a real double-click/two-tabs race (the same
            // class S10 B15 fixed for AssignmentSubmissionService, but
            // there's no unique DB constraint to catch-and-retry against
            // here the way that one had — two concurrent subscribe() calls
            // for the same tenant would otherwise both pass
            // hasLiveSubscription() before either commits, creating two
            // live subscriptions for one tenant with nothing to stop it.
            // Locking the Tenant row itself serializes every subscribe/
            // changePlan/cancel call for that tenant through one at a
            // time, without needing a subscription row to lock before one
            // necessarily exists yet.
            $this->lockTenant($tenant);

            if ($this->hasLiveSubscription($tenant)) {
                throw TenantAlreadySubscribedException::forTenant($tenant->id);
            }

            $subscription = TenantSubscription::withoutEvents(fn () => TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                // The vendor call below reports its own 'created' status —
                // mapped to this app's own 'trialing' rather than adding a
                // vendor-shaped 'created' status locally: "subscription
                // started, no successful charge yet" is exactly what
                // 'trialing' already means here, and B7's webhook is what
                // actually flips it to 'active' once a real payment lands.
                'status' => TenantSubscription::STATUS_TRIALING,
                // Placeholder period until the first real webhook (B7)
                // reports the vendor's own billing-cycle dates — there is no
                // real period yet, since nothing has been charged.
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]));

            $result = $this->provider->createSubscription($subscription);

            $subscription->update(['razorpay_subscription_id' => $result['subscription_id']]);

            $tenant->update(['plan_id' => $plan->id]);

            return $subscription;
        });
    }

    /**
     * S11 B10 (Q5) — moves an already-subscribed tenant onto a different
     * plan immediately, with a prorated adjustment for the remainder of
     * the current period. Refuses a subscribe() call's job (a tenant with
     * no live subscription at all) with TenantNotSubscribedException —
     * symmetric to subscribe() refusing a tenant that already has one.
     */
    public function changePlan(Tenant $tenant, Plan $newPlan): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $newPlan): TenantSubscription {
            // S11 B15 — same tenant-row lock subscribe() uses, same
            // reason: two concurrent changePlan() calls (or a changePlan()
            // racing a cancel()) for the same tenant must not both act on
            // a subscription snapshot read before either committed.
            $this->lockTenant($tenant);

            $subscription = $this->liveSubscriptionFor($tenant);

            if ($subscription === null) {
                throw TenantNotSubscribedException::forTenant($tenant->id);
            }

            $this->guardDowngradeCapacity($tenant, $newPlan);

            $oldPlan = $subscription->plan;
            $proratedAmount = $this->prorate($subscription, $oldPlan, $newPlan);

            $this->provider->changePlan($subscription, $newPlan);

            $subscription->update(['plan_id' => $newPlan->id]);
            $tenant->update(['plan_id' => $newPlan->id]);

            // Written even when the two plans happen to cost the same (a
            // lateral move) — decision 9's whole point is an auditable record
            // of every plan change in this app's own ledger, not just the
            // ones that moved money.
            SubscriptionInvoice::withoutEvents(fn () => SubscriptionInvoice::create([
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $subscription->id,
                'plan_id' => $newPlan->id,
                // Signed, not absolute — negative is a credit (a downgrade),
                // positive an extra charge (an upgrade), collected on the next
                // cycle rather than a live mid-cycle charge (decision 9;
                // design doc §4 — genuinely deferred, not built here).
                'amount' => round($proratedAmount, 2),
                'kind' => SubscriptionInvoice::KIND_PRORATION,
                'status' => SubscriptionInvoice::STATUS_PENDING,
                'period_start' => now(),
                'period_end' => $subscription->current_period_end,
            ]));

            return $subscription->fresh();
        });
    }

    /**
     * S11 B13 — reverts a tenant to decision 3's own legacy-fallback
     * branch (`plan_id = null`), not a special "cancelled plan" row —
     * that fallback already correctly models "no active plan" (the
     * existing `plan_tier` caps), so no new state is needed for it.
     * Same `TenantNotSubscribedException` guard as `changePlan()` — both
     * require a live subscription to act on.
     */
    public function cancel(Tenant $tenant): TenantSubscription
    {
        return DB::transaction(function () use ($tenant): TenantSubscription {
            // S11 B15 — same tenant-row lock, same reason.
            $this->lockTenant($tenant);

            $subscription = $this->liveSubscriptionFor($tenant);

            if ($subscription === null) {
                throw TenantNotSubscribedException::forTenant($tenant->id);
            }

            $this->provider->cancelSubscription($subscription);

            $subscription->update([
                'status' => TenantSubscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $tenant->update(['plan_id' => null]);

            return $subscription->fresh();
        });
    }

    /**
     * (days remaining in the current period / total days in the period) x
     * (new plan price - old plan price) — design doc §2.4 decision 9,
     * verbatim. A period with zero elapsed days left (changing on the
     * exact last instant of a cycle) prorates to 0 rather than dividing by
     * zero.
     */
    protected function prorate(TenantSubscription $subscription, Plan $oldPlan, Plan $newPlan): float
    {
        $periodDays = $subscription->current_period_start->diffInDays($subscription->current_period_end);

        if ($periodDays <= 0) {
            return 0.0;
        }

        // diffInDays()'s own *signed* mode has an easy-to-get-backwards
        // sign convention (S11 B9's own diffInDays() float surprise, same
        // method, different pitfall) — sidestepped entirely by checking
        // direction with isAfter() first and always calling the plain
        // absolute (default) form, which is unambiguously non-negative.
        $daysRemaining = now()->isAfter($subscription->current_period_end)
            ? 0.0
            : now()->diffInDays($subscription->current_period_end);
        $priceDelta = (float) $newPlan->price_inr_per_month - (float) $oldPlan->price_inr_per_month;

        return ($daysRemaining / $periodDays) * $priceDelta;
    }

    /**
     * "Decide and document behaviour here" (B10's own plan note) — blocks
     * rather than silently leaving the tenant over-limit on a plan that
     * can't support what it already has. Checked against seat/storage only
     * — bandwidth and AI spend are daily-resetting quotas, not an
     * accumulated stock, so a downgrade there just means a lower cap
     * starting tomorrow, nothing "stranded" the way an over-limit seat
     * count or storage total would be.
     */
    protected function guardDowngradeCapacity(Tenant $tenant, Plan $newPlan): void
    {
        $overLimits = [];

        if ($newPlan->seat_limit !== null && $this->seatQuota->usedNow($tenant) > $newPlan->seat_limit) {
            $overLimits[] = 'seats';
        }

        if ($this->storageQuota->usedNow($tenant) > ($newPlan->storage_limit_gb * 1024 * 1024 * 1024)) {
            $overLimits[] = 'storage';
        }

        if ($overLimits !== []) {
            throw PlanDowngradeBlockedException::forTenant($tenant->id, $overLimits);
        }
    }

    /**
     * Locked and discarded — the lock itself is the point (serializing
     * every subscribe/changePlan/cancel call for this tenant through one
     * at a time), not the row's own data, so nothing needs to read the
     * result back.
     */
    protected function lockTenant(Tenant $tenant): void
    {
        Tenant::where('id', $tenant->id)->lockForUpdate()->first();
    }

    protected function liveSubscriptionFor(Tenant $tenant): ?TenantSubscription
    {
        return TenantSubscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantSubscription::STATUS_TRIALING,
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_GRACE,
            ])
            ->first();
    }

    protected function hasLiveSubscription(Tenant $tenant): bool
    {
        return $this->liveSubscriptionFor($tenant) !== null;
    }
}
