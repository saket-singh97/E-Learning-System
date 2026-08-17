<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\SeatQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('is unlimited for a tenant with no plan assigned', function () {
    $tenant = Tenant::factory()->create();

    expect(app(SeatQuota::class)->capFor($tenant))->toBeNull()
        ->and(app(SeatQuota::class)->remainingFor($tenant))->toBeNull();
});

it('reads the seat cap from a real plan', function () {
    $plan = Plan::create([
        'code' => 'seat-plan-test',
        'name' => 'Custom',
        'seat_limit' => 3,
        'storage_limit_gb' => 10,
        'bandwidth_limit_gb_per_day' => 10,
        'ai_spend_cap_usd_per_day' => 1,
        'price_inr_per_month' => 0,
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect(app(SeatQuota::class)->capFor($tenant))->toBe(3);
});

it('counts only active institute_admin and instructor accounts, not students or parents', function () {
    $tenant = Tenant::factory()->create();

    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('institute_admin');
    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('instructor');
    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('student');
    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('parent');

    expect(app(SeatQuota::class)->usedNow($tenant))->toBe(2);
});

it('excludes a soft-deleted staff account from the seat count', function () {
    $tenant = Tenant::factory()->create();

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');
    $instructor->delete();

    expect(app(SeatQuota::class)->usedNow($tenant))->toBe(0);
});

it('subtracts used seats from the cap, floored at zero', function () {
    $plan = Plan::create([
        'code' => 'seat-plan-remaining-test',
        'name' => 'Custom',
        'seat_limit' => 1,
        'storage_limit_gb' => 10,
        'bandwidth_limit_gb_per_day' => 10,
        'ai_spend_cap_usd_per_day' => 1,
        'price_inr_per_month' => 0,
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('instructor');
    User::factory()->create(['tenant_id' => $tenant->id])->assignRole('institute_admin');

    expect(app(SeatQuota::class)->remainingFor($tenant))->toBe(0);
});
