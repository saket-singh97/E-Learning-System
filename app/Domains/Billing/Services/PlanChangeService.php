<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\TenantAlreadySubscribedException;
use App\Integrations\SubscriptionProvider;

class PlanChangeService
{
    public function __construct(
        private readonly SubscriptionProvider $provider,
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
    }

    protected function hasLiveSubscription(Tenant $tenant): bool
    {
        return TenantSubscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantSubscription::STATUS_TRIALING,
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_GRACE,
            ])
            ->exists();
    }
}
