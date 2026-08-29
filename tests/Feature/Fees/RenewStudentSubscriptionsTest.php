<?php

use App\Domains\Fees\Jobs\RenewStudentSubscriptions;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Tenancy\Models\Tenant;
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

it('renews a subscription past its current_period_end, advancing the period and billing it', function () {
    [$tenant, $subscription] = dueSubscriptionFixture();
    $originalPeriodEnd = $subscription->current_period_end;

    (new RenewStudentSubscriptions)->handle();

    $fresh = $subscription->fresh();
    expect($fresh->current_period_start->toDateString())->toBe($originalPeriodEnd->toDateString())
        ->and($fresh->current_period_end->isFuture())->toBeTrue();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::where('user_id', $subscription->user_id)->count())->toBe(1);
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
    $afterFirstRun = $subscription->fresh()->current_period_end;

    (new RenewStudentSubscriptions)->handle();

    expect($subscription->fresh()->current_period_end->equalTo($afterFirstRun))->toBeTrue();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::where('user_id', $subscription->user_id)->count())->toBe(1);
});

it('never renews a cancelled subscription even if its period has lapsed', function () {
    [$tenant, $subscription] = dueSubscriptionFixture('cancelled');

    (new RenewStudentSubscriptions)->handle();

    app(CurrentTenant::class)->set($tenant);
    expect(FeeInvoice::count())->toBe(0);
});
