<?php

use App\Domains\Billing\Jobs\SuspendOverdueTenantSubscriptions;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\PlanChangeService;
use App\Domains\Billing\Services\SubscriptionReconciler;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpaySubscriptionDriver;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S11 B16 — the one thing no per-block test could cover: the full real
 * story chained in one run, same posture S7's own `DemoScriptE2ETest`
 * established (mirrored, not copied — this app's own precedent for "the
 * individual pieces are all unit-tested, but nothing proves they still
 * fit together end to end"). Chains subscribe -> charged -> payment
 * failed (grace) -> the daily sweep (suspend) -> a recovered charged
 * webhook (un-suspend, S11 B16's own fix) -> cancel, asserting the real
 * state transition after every step, not just the terminal one.
 */
it('runs the full subscribe -> charge -> grace -> suspend -> recover -> cancel lifecycle', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::create([
        'code' => 'growth-e2e-lifecycle-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    $planChange = app(PlanChangeService::class);
    $reconciler = app(SubscriptionReconciler::class);

    $sign = fn (string $payload): string => hash_hmac('sha256', $payload, FakeRazorpaySubscriptionDriver::FAKE_SECRET);
    $webhook = function (string $razorpaySubscriptionId, string $event, ?string $paymentId) use ($sign, $reconciler): void {
        $payload = json_encode([
            'event' => $event,
            'payload' => [
                'subscription' => ['entity' => ['id' => $razorpaySubscriptionId, 'current_start' => now()->timestamp, 'current_end' => now()->addMonth()->timestamp]],
                'payment' => $paymentId !== null ? ['entity' => ['id' => $paymentId, 'amount' => 799900]] : [],
            ],
        ]);

        $reconciler->reconcile($payload, $sign($payload));
    };

    // 1. Subscribe — Super-panel-initiated, no live subscription yet.
    $subscription = $planChange->subscribe($tenant, $plan);
    expect($subscription->status)->toBe(TenantSubscription::STATUS_TRIALING)
        ->and($tenant->fresh()->plan_id)->toBe($plan->id);

    $razorpaySubscriptionId = $subscription->razorpay_subscription_id;

    // 2. First payment succeeds — a real webhook, not a direct status write.
    $webhook($razorpaySubscriptionId, 'subscription.charged', 'pay_e2e_1');
    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_ACTIVE);

    // 3. A later payment fails — grace begins.
    $webhook($razorpaySubscriptionId, 'payment.failed', 'pay_e2e_fail');
    $fresh = $subscription->fresh();
    expect($fresh->status)->toBe(TenantSubscription::STATUS_GRACE)
        ->and($fresh->grace_ends_at)->not->toBeNull();

    // 4. Grace deadline passes with no recovery — the daily sweep suspends
    // both the subscription and, critically, the tenant itself (the
    // actual login gate).
    $subscription->update(['grace_ends_at' => now()->subDay()]);
    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_SUSPENDED)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_SUSPENDED);

    // 5. A late/retried charge finally succeeds — S11 B16's own fix: this
    // must recover both rows, not just the subscription's own status.
    // Before that fix, the tenant stayed locked out forever even though
    // billing had genuinely recovered.
    $webhook($razorpaySubscriptionId, 'subscription.charged', 'pay_e2e_recovered');

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_ACTIVE)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_ACTIVE);

    // 6. The institute decides to stop paying — cancels outright. Also
    // exercises S11 B16's other fix: liveSubscriptionFor() including
    // SUSPENDED wouldn't matter here (already recovered to ACTIVE), but
    // cancelling straight from a genuinely active state must still work
    // as it always did.
    $planChange->cancel($tenant);

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_CANCELLED)
        ->and($tenant->fresh()->plan_id)->toBeNull();
});
