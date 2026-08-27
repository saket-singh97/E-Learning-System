<?php

use App\Domains\Growth\Livewire\AffiliateDashboard;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('shows the logged in affiliates own codes', function () {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    AffiliateCode::create([
        'owner_user_id' => $owner->id,
        'code' => 'MINE1234',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test(AffiliateDashboard::class)
        ->assertSee('MINE1234');
});

it('never shows another affiliates own code or numbers', function () {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherOwner = User::factory()->create(['tenant_id' => $this->tenant->id]);

    AffiliateCode::create([
        'owner_user_id' => $owner->id,
        'code' => 'MINE1234',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);
    AffiliateCode::create([
        'owner_user_id' => $otherOwner->id,
        'code' => 'THEIRS999',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test(AffiliateDashboard::class)
        ->assertSee('MINE1234')
        ->assertDontSee('THEIRS999');
});

it('prompts a guest to log in instead of erroring', function () {
    Livewire::test(AffiliateDashboard::class)
        ->assertSee('Log in to see your affiliate dashboard.');
});
