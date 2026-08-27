<?php

use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages\CreateStudentMembershipPlan;
use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages\ListStudentMembershipPlans;
use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\StudentMembershipPlanResource;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Tenancy\Models\Tenant;
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
        ->get(StudentMembershipPlanResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a monthly plan', function () {
    Livewire::test(CreateStudentMembershipPlan::class)
        ->fillForm([
            'name' => 'Monthly Access',
            'price' => 499,
            'billing_interval' => 'monthly',
            'trial_days' => 7,
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = StudentMembershipPlan::sole();

    expect($plan->billing_interval)->toBe('monthly')
        ->and((float) $plan->price)->toBe(499.0)
        ->and($plan->trial_days)->toBe(7)
        ->and($plan->status)->toBe('active');
});

it('creates a yearly plan', function () {
    Livewire::test(CreateStudentMembershipPlan::class)
        ->fillForm([
            'name' => 'Yearly Access',
            'price' => 4999,
            'billing_interval' => 'yearly',
            'trial_days' => 0,
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StudentMembershipPlan::sole()->billing_interval)->toBe('yearly');
});

it('lists only the current tenants own plans', function () {
    $ownPlan = StudentMembershipPlan::create([
        'name' => 'Ours',
        'price' => 100,
        'billing_interval' => 'monthly',
        'status' => 'active',
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    StudentMembershipPlan::create([
        'name' => 'Theirs',
        'price' => 200,
        'billing_interval' => 'monthly',
        'status' => 'active',
    ]);

    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(ListStudentMembershipPlans::class)
        ->assertCanSeeTableRecords([$ownPlan])
        ->assertCountTableRecords(1);
});
