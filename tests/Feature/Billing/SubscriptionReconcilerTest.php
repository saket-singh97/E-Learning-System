<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\SubscriptionReconciler;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Integrations\Drivers\FakeRazorpaySubscriptionDriver;
use App\Models\User;
use App\Notifications\GracePeriodReminder;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->plan = Plan::create([
        'code' => 'growth-reconciler-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    $this->subscription = TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_TRIALING,
        'razorpay_subscription_id' => 'sub_test_abc123',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->reconciler = app(SubscriptionReconciler::class);

    $this->payload = fn (string $event, ?string $paymentId = 'pay_test123'): string => json_encode([
        'event' => $event,
        'payload' => [
            'subscription' => ['entity' => ['id' => 'sub_test_abc123', 'current_start' => now()->timestamp, 'current_end' => now()->addMonth()->timestamp]],
            'payment' => $paymentId !== null ? ['entity' => ['id' => $paymentId, 'amount' => 799900]] : [],
        ],
    ]);

    $this->sign = fn (string $payload): string => hash_hmac('sha256', $payload, FakeRazorpaySubscriptionDriver::FAKE_SECRET);
});

it('activates the subscription and records a paid invoice on subscription.charged', function () {
    $payload = ($this->payload)('subscription.charged');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    $fresh = $this->subscription->fresh();
    expect($fresh->status)->toBe(TenantSubscription::STATUS_ACTIVE)
        ->and($fresh->grace_ends_at)->toBeNull();

    $invoice = SubscriptionInvoice::withoutGlobalScope('tenant')->where('tenant_subscription_id', $fresh->id)->sole();
    expect((float) $invoice->amount)->toBe(7999.0)
        ->and($invoice->status)->toBe(SubscriptionInvoice::STATUS_PAID)
        ->and($invoice->razorpay_payment_id)->toBe('pay_test123');
});

it('throws on an invalid signature and touches nothing', function () {
    $payload = ($this->payload)('subscription.charged');

    expect(fn () => $this->reconciler->reconcile($payload, 'not-a-real-signature'))
        ->toThrow(InvalidWebhookSignatureException::class);

    expect($this->subscription->fresh()->status)->toBe(TenantSubscription::STATUS_TRIALING);
});

it('is idempotent: the same charged payload processed twice only writes one invoice', function () {
    $payload = ($this->payload)('subscription.charged');
    $signature = ($this->sign)($payload);

    $this->reconciler->reconcile($payload, $signature);
    $this->reconciler->reconcile($payload, $signature);

    expect(SubscriptionInvoice::withoutGlobalScope('tenant')->where('tenant_subscription_id', $this->subscription->id)->count())->toBe(1);
});

it('moves the subscription to grace and records a failed invoice on payment.failed', function () {
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $payload = ($this->payload)('payment.failed', paymentId: 'pay_fail_1');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    $fresh = $this->subscription->fresh();
    expect($fresh->status)->toBe(TenantSubscription::STATUS_GRACE)
        ->and($fresh->grace_ends_at->toDateString())->toBe(now()->addDays(7)->toDateString());

    $invoice = SubscriptionInvoice::withoutGlobalScope('tenant')->where('razorpay_payment_id', 'pay_fail_1')->sole();
    expect($invoice->status)->toBe(SubscriptionInvoice::STATUS_FAILED);
});

it('does not push the grace deadline further out on a second, distinct failure', function () {
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $first = ($this->payload)('payment.failed', paymentId: 'pay_fail_1');
    $this->reconciler->reconcile($first, ($this->sign)($first));
    $originalDeadline = $this->subscription->fresh()->grace_ends_at;

    $this->travel(2)->days();

    $second = ($this->payload)('payment.failed', paymentId: 'pay_fail_2');
    $this->reconciler->reconcile($second, ($this->sign)($second));

    expect($this->subscription->fresh()->grace_ends_at->equalTo($originalDeadline))->toBeTrue();
});

it('notifies every institute_admin the moment a subscription enters grace (S11 B14)', function () {
    Notification::fake();
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $payload = ($this->payload)('payment.failed', paymentId: 'pay_fail_1');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    Notification::assertSentTo(
        $admin,
        fn (GracePeriodReminder $notification) => $notification->stage === 'entered_grace'
            && $notification->subscription->id === $this->subscription->id,
    );
    Notification::assertNotSentTo($instructor, GracePeriodReminder::class);
});

it('does not re-notify entered_grace on a second distinct failure while already in grace', function () {
    Notification::fake();
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $first = ($this->payload)('payment.failed', paymentId: 'pay_fail_1');
    $this->reconciler->reconcile($first, ($this->sign)($first));

    $second = ($this->payload)('payment.failed', paymentId: 'pay_fail_2');
    $this->reconciler->reconcile($second, ($this->sign)($second));

    Notification::assertSentToTimes($admin, GracePeriodReminder::class, 1);
});

it('cancels the subscription on subscription.cancelled', function () {
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $payload = ($this->payload)('subscription.cancelled', paymentId: null);
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    $fresh = $this->subscription->fresh();
    expect($fresh->status)->toBe(TenantSubscription::STATUS_CANCELLED)
        ->and($fresh->cancelled_at)->not->toBeNull();
});

it('also cancels the subscription on subscription.completed', function () {
    $this->subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $payload = ($this->payload)('subscription.completed', paymentId: null);
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->subscription->fresh()->status)->toBe(TenantSubscription::STATUS_CANCELLED);
});

it('ignores an event type it does not recognise', function () {
    $payload = ($this->payload)('subscription.pending');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->subscription->fresh()->status)->toBe(TenantSubscription::STATUS_TRIALING);
});

it('ignores a payload whose razorpay_subscription_id does not match any local row', function () {
    $payload = json_encode([
        'event' => 'subscription.charged',
        'payload' => ['subscription' => ['entity' => ['id' => 'sub_unknown_xyz']]],
    ]);

    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->subscription->fresh()->status)->toBe(TenantSubscription::STATUS_TRIALING);
});
