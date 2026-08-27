<?php

use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Fees\Services\StudentSubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->service = app(StudentSubscriptionService::class);

    $this->reconcileCapture = function (FeePayment $payment): void {
        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => ['id' => 'pay_'.uniqid(), 'order_id' => $payment->provider_order_id]],
                'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $this->tenant->id]]],
            ],
        ]);
        $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

        app(PaymentReconciler::class)->reconcile($payload, $signature);
    };
});

it('starts a trial with no charge at all', function () {
    $plan = StudentMembershipPlan::create(['name' => 'Trial Plan', 'price' => 499, 'billing_interval' => 'monthly', 'trial_days' => 7, 'status' => 'active']);

    $subscription = $this->service->startTrial($plan, $this->student);

    expect($subscription->status)->toBe('trialing')
        ->and($subscription->trial_ends_at->diffInDays(now()))->toBeLessThanOrEqual(7)
        ->and(FeePayment::count())->toBe(0);
});

it('activates a subscription once its first payment is actually captured', function () {
    $plan = StudentMembershipPlan::create(['name' => 'Monthly', 'price' => 499, 'billing_interval' => 'monthly', 'status' => 'active']);

    $payment = $this->service->checkout($plan, $this->student);
    expect(StudentSubscription::count())->toBe(0);

    ($this->reconcileCapture)($payment);

    $subscription = StudentSubscription::sole();
    expect($subscription->status)->toBe('active')
        ->and($subscription->user_id)->toBe($this->student->id)
        ->and($subscription->student_membership_plan_id)->toBe($plan->id)
        ->and($subscription->current_period_end->toDateString())->toBe(now()->addMonth()->toDateString());
});

it('converts an existing trial to active on payment rather than creating a second row', function () {
    $plan = StudentMembershipPlan::create(['name' => 'Yearly', 'price' => 4999, 'billing_interval' => 'yearly', 'trial_days' => 14, 'status' => 'active']);
    $trial = $this->service->startTrial($plan, $this->student);

    $payment = $this->service->checkout($plan, $this->student);
    ($this->reconcileCapture)($payment);

    expect(StudentSubscription::count())->toBe(1)
        ->and($trial->fresh()->status)->toBe('active');
});

it('matches a known worked example for mid-cycle upgrade proration', function () {
    $oldPlan = StudentMembershipPlan::create(['name' => 'Basic', 'price' => 300, 'billing_interval' => 'monthly', 'status' => 'active']);
    $newPlan = StudentMembershipPlan::create(['name' => 'Pro', 'price' => 900, 'billing_interval' => 'monthly', 'status' => 'active']);

    $subscription = StudentSubscription::create([
        'user_id' => $this->student->id,
        'student_membership_plan_id' => $oldPlan->id,
        'status' => 'active',
        'current_period_start' => Carbon\Carbon::parse('2026-09-01'),
        'current_period_end' => Carbon\Carbon::parse('2026-10-01'),
    ]);

    // 30-day period, switching with 10 days remaining: unused credit on
    // the old plan is 300 * 10/30 = 100; new plan's own share of those same
    // 10 days is 900 * 10/30 = 300; net charge now is 300 - 100 = 200.
    $result = $this->service->changePlan($subscription, $newPlan, Carbon\Carbon::parse('2026-09-21'));

    expect($result['proration_amount'])->toBe(200.0)
        ->and($result['subscription']->student_membership_plan_id)->toBe($newPlan->id);
});

it('refuses to renew a cancelled subscription', function () {
    $plan = StudentMembershipPlan::create(['name' => 'Monthly', 'price' => 499, 'billing_interval' => 'monthly', 'status' => 'active']);
    $subscription = StudentSubscription::create([
        'user_id' => $this->student->id,
        'student_membership_plan_id' => $plan->id,
        'status' => 'active',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ]);

    $this->service->cancel($subscription);

    $payment = $this->service->renew($subscription->fresh());

    expect($payment)->toBeNull()
        ->and(FeePayment::count())->toBe(0)
        ->and($subscription->fresh()->status)->toBe('cancelled');
});
