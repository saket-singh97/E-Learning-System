<?php

use App\Domains\Billing\Filament\Resources\Plans\PlanResource;
use App\Domains\Billing\Filament\Resources\Plans\Pages\CreatePlan;
use App\Domains\Billing\Filament\Resources\Plans\Pages\EditPlan;
use App\Domains\Billing\Filament\Resources\Plans\Pages\ListPlans;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->superAdmin->assignRole('super_admin');

    $this->actingAs($this->superAdmin)
        ->get(PlanResource::getUrl('create', panel: 'super'))
        ->assertOk();
});

function billingPlanFormData(array $overrides = []): array
{
    return array_merge([
        'code' => 'row-'.uniqid(),
        'name' => 'Row Plan',
        'seat_limit' => 5,
        'storage_limit_gb' => 25,
        'bandwidth_limit_gb_per_day' => 20,
        'ai_spend_cap_usd_per_day' => 5,
        'price_inr_per_month' => 2999,
        'razorpay_plan_id' => null,
        'is_active' => true,
    ], $overrides);
}

it('creates a plan', function () {
    Livewire::test(CreatePlan::class)
        ->fillForm(billingPlanFormData(['code' => 'starter-test', 'name' => 'Starter']))
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::where('code', 'starter-test')->sole();

    expect($plan->name)->toBe('Starter')
        ->and($plan->seat_limit)->toBe(5)
        ->and($plan->storage_limit_gb)->toBe(25)
        ->and($plan->is_active)->toBeTrue();
});

it('allows a blank seat limit for unlimited seats', function () {
    Livewire::test(CreatePlan::class)
        ->fillForm(billingPlanFormData(['code' => 'enterprise-test', 'seat_limit' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Plan::where('code', 'enterprise-test')->sole()->seat_limit)->toBeNull();
});

it('rejects a duplicate plan code', function () {
    Plan::create(billingPlanFormData(['code' => 'dup-test']));

    Livewire::test(CreatePlan::class)
        ->fillForm(billingPlanFormData(['code' => 'dup-test']))
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Plan::where('code', 'dup-test')->count())->toBe(1);
});

it('edits a plan without changing its code', function () {
    $plan = Plan::create(billingPlanFormData(['code' => 'edit-test']));

    Livewire::test(EditPlan::class, ['record' => $plan->id])
        ->fillForm(['name' => 'Renamed', 'price_inr_per_month' => 4999, 'code' => 'attempted-change'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $plan->fresh();

    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->price_inr_per_month)->toEqual(4999)
        ->and($fresh->code)->toBe('edit-test');
});

it('hides delete for a plan a tenant is currently assigned to', function () {
    $plan = Plan::create(billingPlanFormData(['code' => 'assigned-test']));
    Tenant::factory()->create(['plan_id' => $plan->id]);

    $this->actingAs($this->superAdmin)
        ->get(PlanResource::getUrl('index', panel: 'super'))
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->assertTableActionHidden('delete', $plan);
});

it('hides delete for a plan with subscription history', function () {
    $plan = Plan::create(billingPlanFormData(['code' => 'subscribed-test']));
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => 'cancelled',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
    ]);

    app(CurrentTenant::class)->clear();

    $this->actingAs($this->superAdmin)
        ->get(PlanResource::getUrl('index', panel: 'super'))
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->assertTableActionHidden('delete', $plan);
});

it('allows deleting a plan no tenant or subscription references', function () {
    $plan = Plan::create(billingPlanFormData(['code' => 'unused-test']));

    $this->actingAs($this->superAdmin)
        ->get(PlanResource::getUrl('index', panel: 'super'))
        ->assertOk();

    Livewire::test(ListPlans::class)
        ->assertTableActionVisible('delete', $plan);
});
