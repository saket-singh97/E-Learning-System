<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\AtRiskThresholdSettings;
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
        ->get(AtRiskThresholdSettings::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('saves a tenant-specific at-risk threshold override', function () {
    Livewire::test(AtRiskThresholdSettings::class)
        ->set('data.at_risk_threshold_percent', 75)
        ->callAction('save');

    expect($this->tenant->fresh()->at_risk_threshold_percent)->toBe(75.0);
});

it('clears the override back to the default when saved blank', function () {
    $this->tenant->update(['at_risk_threshold_percent' => 75]);
    // beforeEach's real HTTP request bound a separate Tenant instance into
    // CurrentTenant — re-bind to this now-updated object so mount() and the
    // save action both see 75, not a stale pre-update copy.
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(AtRiskThresholdSettings::class)
        ->set('data.at_risk_threshold_percent', null)
        ->callAction('save');

    expect($this->tenant->fresh()->at_risk_threshold_percent)->toBeNull();
});
