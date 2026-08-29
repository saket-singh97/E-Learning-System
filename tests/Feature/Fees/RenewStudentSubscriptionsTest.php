<?php

use App\Domains\Fees\Jobs\RenewStudentSubscriptions;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dueSubscriptionFixture(string $status = 'active', ?string $periodEnd = null): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $plan = StudentMembershipPlan::create([
        'name' => 'Monthly Plan',
        'price' => 499,
        'billing_interval' => 'monthly',
        'trial_days' => 7,
        'status' => 'active',
    ]);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $subscription = StudentSubscription::create([
        'user_id' => $user->id,
        'student_membership_plan_id' => $plan->id,
        'status' => $status,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => $periodEnd ?? now()->subDay(),
    ]);

    app(CurrentTenant::class)->clear();

    return [$tenant, $subscription];
}

it('bills a due subscription and marks it past_due, without granting a free period', function () {
    // S22 B13 review pass regression: renew()/checkout() only creates an
    // unpaid order — the period must NOT advance until that order is
    // actually captured, or the sweep would grant free access forever.
    [$tenant, $subscription] = dueSubscriptionFixture();
    $originalPeriodEnd = $subscription->current_period_end;

    (new RenewStudentSubscriptions)->handle();

    $fresh = $subscription->fresh();
    expect($fresh->status)->toBe('past_due')
        ->and($fresh->current_period_end->equalTo($originalPeriodEnd))->toBeTrue();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::where('user_id', $subscription->user_id)->count())->toBe(1);
});

it('advances the period and reactivates only once the renewal invoice is actually captured', function () {
    [$tenant, $subscription] = dueSubscriptionFixture();

    (new RenewStudentSubscriptions)->handle();
    app(CurrentTenant::class)->set($tenant);
    expect($subscription->fresh()->status)->toBe('past_due');

    $payment = FeePayment::where('fee_invoice_id', FeeInvoice::where('user_id', $subscription->user_id)->sole()->id)->sole();

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_renewal', 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    app(PaymentReconciler::class)->reconcile($payload, $signature);

    $fresh = $subscription->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->current_period_end->isFuture())->toBeTrue();
});

it('leaves a subscription not yet due untouched', function () {
    [$tenant, $subscription] = dueSubscriptionFixture('active', now()->addWeek()->toDateTimeString());
    $originalPeriodEnd = $subscription->current_period_end;

    (new RenewStudentSubscriptions)->handle();

    expect($subscription->fresh()->current_period_end->equalTo($originalPeriodEnd))->toBeTrue();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::count())->toBe(0);
});

it('is idempotent for an already-renewed row on a second sweep run', function () {
    [$tenant, $subscription] = dueSubscriptionFixture();

    (new RenewStudentSubscriptions)->handle();
    (new RenewStudentSubscriptions)->handle();

    app(CurrentTenant::class)->set($tenant);
    expect($subscription->fresh()->status)->toBe('past_due')
        ->and(FeeInvoice::where('user_id', $subscription->user_id)->count())->toBe(1);
});

it('never renews a cancelled subscription even if its period has lapsed', function () {
    [$tenant, $subscription] = dueSubscriptionFixture('cancelled');

    (new RenewStudentSubscriptions)->handle();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::count())->toBe(0);
});
