<?php

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeAnalyticsService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function analyticsInvoice(Tenant $tenant, string $status, float $totalAmount, string $dueDate): FeeInvoice
{
    app(CurrentTenant::class)->set($tenant);

    return FeeInvoice::create([
        'user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'sequence_number' => FeeInvoice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count() + 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Row',
        'amount' => $totalAmount,
        'gst_amount' => 0,
        'total_amount' => $totalAmount,
        'due_date' => $dueDate,
        'status' => $status,
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('computes collection rate as paid over non-cancelled total, matching a hand-computed value', function () {
    analyticsInvoice($this->tenant, 'paid', 1000, now()->toDateString());
    analyticsInvoice($this->tenant, 'pending', 500, now()->addDays(10)->toDateString());
    analyticsInvoice($this->tenant, 'overdue', 300, now()->subDays(5)->toDateString());
    analyticsInvoice($this->tenant, 'cancelled', 9999, now()->toDateString());

    $rate = app(FeeAnalyticsService::class)->collectionRate($this->tenant);

    // 1000 / (1000 + 500 + 300) * 100, cancelled excluded entirely.
    expect($rate)->toBe(round(1000 / 1800 * 100, 2));
});

it('returns 0.0 collection rate for a tenant with zero invoices', function () {
    expect(app(FeeAnalyticsService::class)->collectionRate($this->tenant))->toBe(0.0);
});

it('sums only unpaid invoices with a future due_date for projected revenue', function () {
    analyticsInvoice($this->tenant, 'pending', 400, now()->addDays(10)->toDateString());
    analyticsInvoice($this->tenant, 'pending', 999, now()->subDays(5)->toDateString());
    analyticsInvoice($this->tenant, 'paid', 777, now()->addDays(10)->toDateString());

    expect(app(FeeAnalyticsService::class)->projectedRevenue($this->tenant))->toBe(400.0);
});

it('buckets defaulters by exact days-past-due boundaries: 30/31 and 60/61', function () {
    analyticsInvoice($this->tenant, 'pending', 100, now()->subDays(30)->toDateString());
    analyticsInvoice($this->tenant, 'overdue', 200, now()->subDays(31)->toDateString());
    analyticsInvoice($this->tenant, 'overdue', 300, now()->subDays(60)->toDateString());
    analyticsInvoice($this->tenant, 'overdue', 400, now()->subDays(61)->toDateString());

    $ageing = app(FeeAnalyticsService::class)->defaulterAgeing($this->tenant);

    expect($ageing['0-30'])->toBe(['count' => 1, 'amount' => 100.0])
        ->and($ageing['31-60'])->toBe(['count' => 2, 'amount' => 500.0])
        ->and($ageing['60+'])->toBe(['count' => 1, 'amount' => 400.0]);
});
