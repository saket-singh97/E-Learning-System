<?php

use App\Domains\Fees\Filament\Resources\FeeCoupons\FeeCouponResource;
use App\Domains\Fees\Filament\Resources\FeeCoupons\Pages\CreateFeeCoupon;
use App\Domains\Fees\Filament\Resources\FeeCoupons\Pages\EditFeeCoupon;
use App\Domains\Fees\Models\FeeCoupon;
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
        ->get(FeeCouponResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a coupon, upper-casing the code regardless of how it was typed', function () {
    Livewire::test(CreateFeeCoupon::class)
        ->fillForm([
            'code' => 'summer25',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'usage_limit' => 100,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = FeeCoupon::sole();

    expect($coupon->code)->toBe('SUMMER25')
        ->and($coupon->status)->toBe('active')
        ->and((float) $coupon->discount_value)->toBe(25.0)
        ->and($coupon->usage_limit)->toBe(100);
});

it('edits a coupon, e.g. disabling it', function () {
    $coupon = FeeCoupon::create([
        'code' => 'OLDCODE',
        'discount_type' => 'fixed',
        'discount_value' => 50,
        'status' => 'active',
    ]);

    Livewire::test(EditFeeCoupon::class, ['record' => $coupon->id])
        ->fillForm(['status' => 'disabled'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($coupon->fresh()->status)->toBe('disabled');
});
