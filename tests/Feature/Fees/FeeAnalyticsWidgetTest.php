<?php

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Widgets\FeeAnalyticsWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk();
});

function feeAnalyticsWidgetInvoice(Tenant $tenant, string $status, float $totalAmount, string $dueDate): FeeInvoice
{
    return FeeInvoice::create([
        'user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'sequence_number' => FeeInvoice::where('tenant_id', $tenant->id)->count() + 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Fee',
        'amount' => $totalAmount,
        'gst_amount' => 0,
        'total_amount' => $totalAmount,
        'due_date' => $dueDate,
        'status' => $status,
    ]);
}

it('renders correct collection rate, projected revenue, and defaulter counts for a seeded tenant', function () {
    feeAnalyticsWidgetInvoice($this->tenant, 'paid', 1000, now()->toDateString());
    feeAnalyticsWidgetInvoice($this->tenant, 'pending', 500, now()->addDays(10)->toDateString());
    feeAnalyticsWidgetInvoice($this->tenant, 'overdue', 300, now()->subDays(31)->toDateString());

    Livewire::test(FeeAnalyticsWidget::class)
        ->assertSeeInOrder(['Collection rate', '55.56%'])
        ->assertSeeInOrder(['Projected revenue', '₹500.00'])
        ->assertSeeInOrder(['Defaulters', '1'])
        ->assertSee('31-60d: 1');
});

it('shows a 0%/empty state for a tenant with zero invoices, not a divide-by-zero crash', function () {
    Livewire::test(FeeAnalyticsWidget::class)
        ->assertSeeInOrder(['Collection rate', '0.00%'])
        ->assertSeeInOrder(['Projected revenue', '₹0.00'])
        ->assertSeeInOrder(['Defaulters', '0']);
});
