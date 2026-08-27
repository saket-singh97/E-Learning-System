<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\PlatformRevenueAnalytics;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->plan = Plan::create([
        'code' => 'growth-revenue-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 5000,
    ]);

    $this->service = app(PlatformRevenueAnalytics::class);

    $this->makeTenant = function (string $name, string $subdomain): Tenant {
        $tenant = Tenant::factory()->create(['name' => $name, 'subdomain' => $subdomain]);
        app(CurrentTenant::class)->set($tenant);

        return $tenant;
    };

    $this->makeSubscription = function (Tenant $tenant, string $status): TenantSubscription {
        app(CurrentTenant::class)->set($tenant);

        return TenantSubscription::create([
            'plan_id' => $this->plan->id,
            'status' => $status,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    };

    $this->makePayment = function (Tenant $tenant, string $status, float $amount, string $currency = 'INR'): FeePayment {
        app(CurrentTenant::class)->set($tenant);

        $invoice = FeeInvoice::create([
            'user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'sequence_number' => FeeInvoice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count() + 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Row',
            'amount' => $amount,
            'gst_amount' => 0,
            'total_amount' => $amount,
            'currency' => $currency,
            'due_date' => now()->toDateString(),
            'status' => 'paid',
        ]);

        return FeePayment::create([
            'fee_invoice_id' => $invoice->id,
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => $amount,
            'status' => $status,
        ]);
    };

    app(CurrentTenant::class)->clear();
});

it('computes MRR as the sum of active subscriptions plan prices, ARR as MRR times 12', function () {
    $tenantA = ($this->makeTenant)('Physics Academy', 'physics');
    $tenantB = ($this->makeTenant)('Chemistry Academy', 'chemistry');
    ($this->makeSubscription)($tenantA, TenantSubscription::STATUS_ACTIVE);
    ($this->makeSubscription)($tenantB, TenantSubscription::STATUS_ACTIVE);
    app(CurrentTenant::class)->clear();

    expect($this->service->mrr())->toBe(10000.0)
        ->and($this->service->arr())->toBe(120000.0);
});

it('excludes a cancelled subscription from MRR', function () {
    $tenantA = ($this->makeTenant)('Physics Academy', 'physics');
    $tenantB = ($this->makeTenant)('Chemistry Academy', 'chemistry');
    ($this->makeSubscription)($tenantA, TenantSubscription::STATUS_ACTIVE);
    ($this->makeSubscription)($tenantB, TenantSubscription::STATUS_CANCELLED);
    app(CurrentTenant::class)->clear();

    expect($this->service->mrr())->toBe(5000.0);
});

it('is unaffected by whichever tenant CurrentTenant happens to be bound to', function () {
    $tenantA = ($this->makeTenant)('Physics Academy', 'physics');
    $tenantB = ($this->makeTenant)('Chemistry Academy', 'chemistry');
    ($this->makeSubscription)($tenantA, TenantSubscription::STATUS_ACTIVE);
    ($this->makeSubscription)($tenantB, TenantSubscription::STATUS_ACTIVE);

    app(CurrentTenant::class)->set($tenantA);

    expect($this->service->mrr())->toBe(10000.0);
});

it('matches a known mix of captured/failed payments for the success/failure rate', function () {
    $tenant = ($this->makeTenant)('Physics Academy', 'physics');
    ($this->makePayment)($tenant, 'captured', 100);
    ($this->makePayment)($tenant, 'captured', 100);
    ($this->makePayment)($tenant, 'captured', 100);
    ($this->makePayment)($tenant, 'failed', 100);
    ($this->makePayment)($tenant, 'created', 100);
    app(CurrentTenant::class)->clear();

    $rate = $this->service->paymentSuccessFailureRate();

    expect($rate['captured'])->toBe(3)
        ->and($rate['failed'])->toBe(1)
        ->and($rate['success_rate'])->toBe(75.0);
});

it('breaks revenue down per institute, never blending two institutes or two currencies into one total', function () {
    $tenantA = ($this->makeTenant)('Physics Academy', 'physics');
    $tenantB = ($this->makeTenant)('US Prep', 'usprep');
    ($this->makePayment)($tenantA, 'captured', 1000, 'INR');
    ($this->makePayment)($tenantA, 'captured', 500, 'INR');
    ($this->makePayment)($tenantB, 'captured', 200, 'USD');
    ($this->makePayment)($tenantA, 'failed', 9999, 'INR');
    app(CurrentTenant::class)->clear();

    $rows = $this->service->perInstituteRevenue()->keyBy('tenant_name');

    expect($rows->get('Physics Academy')['amount'])->toBe(1500.0)
        ->and($rows->get('Physics Academy')['currency'])->toBe('INR')
        ->and($rows->get('US Prep')['amount'])->toBe(200.0)
        ->and($rows->get('US Prep')['currency'])->toBe('USD')
        ->and($rows)->toHaveCount(2);
});
