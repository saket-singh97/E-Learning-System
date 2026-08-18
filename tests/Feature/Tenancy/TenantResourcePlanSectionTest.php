<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Security\Models\AuditLogEntry;
use App\Domains\Tenancy\Filament\Resources\Tenants\Pages\EditTenant;
use App\Domains\Tenancy\Filament\Resources\Tenants\TenantResource;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->superAdmin->assignRole('super_admin');

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $this->plan = Plan::create([
        'code' => 'growth-ui-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    $this->actingAs($this->superAdmin)
        ->get(TenantResource::getUrl('edit', ['record' => $this->tenant], panel: 'super'))
        ->assertOk();
});

it('assigns a plan to a tenant via the Assign Plan action', function () {
    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->callAction('assignPlan', data: ['plan_id' => $this->plan->id]);

    expect($this->tenant->fresh()->plan_id)->toBe($this->plan->id);

    $subscription = TenantSubscription::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->sole();
    expect($subscription->plan_id)->toBe($this->plan->id)
        ->and($subscription->status)->toBe(TenantSubscription::STATUS_TRIALING);
});

it('hides the Assign Plan action once a tenant already has one', function () {
    $this->tenant->update(['plan_id' => $this->plan->id]);

    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->assertActionHidden('assignPlan');
});

it('hides the Change Plan action until a tenant has a plan', function () {
    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->assertActionHidden('changePlan');
});

it('changes a tenants plan via the Change Plan action (S11 B10)', function () {
    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->callAction('assignPlan', data: ['plan_id' => $this->plan->id]);

    TenantSubscription::withoutGlobalScope('tenant')
        ->where('tenant_id', $this->tenant->id)
        ->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $enterprise = Plan::create([
        'code' => 'enterprise-ui-test',
        'name' => 'Enterprise',
        'storage_limit_gb' => 500,
        'bandwidth_limit_gb_per_day' => 200,
        'ai_spend_cap_usd_per_day' => 50,
        'price_inr_per_month' => 19999,
    ]);

    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->assertActionVisible('changePlan')
        ->callAction('changePlan', data: ['plan_id' => $enterprise->id]);

    expect($this->tenant->fresh()->plan_id)->toBe($enterprise->id);

    $entry = AuditLogEntry::latest('id')->first();
    expect($entry->action)->toBe('plan.updated')
        ->and($entry->actor_id)->toBe($this->superAdmin->id)
        ->and($entry->subject_id)->toBe($this->tenant->id)
        ->and($entry->metadata['to_plan_id'])->toBe($enterprise->id);
});

it('hides the Cancel Subscription action until a tenant has a plan', function () {
    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->assertActionHidden('cancelSubscription');
});

it('cancels a subscription via the Cancel Subscription action (S11 B13)', function () {
    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->callAction('assignPlan', data: ['plan_id' => $this->plan->id]);

    Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
        ->assertActionVisible('cancelSubscription')
        ->callAction('cancelSubscription');

    expect($this->tenant->fresh()->plan_id)->toBeNull();

    $subscription = TenantSubscription::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->sole();
    expect($subscription->status)->toBe(TenantSubscription::STATUS_CANCELLED);
});

it('shows the current plan name in the tenant list once assigned', function () {
    $this->tenant->update(['plan_id' => $this->plan->id]);

    $this->actingAs($this->superAdmin)
        ->get(TenantResource::getUrl('index', panel: 'super'))
        ->assertOk()
        ->assertSee('Growth');
});
