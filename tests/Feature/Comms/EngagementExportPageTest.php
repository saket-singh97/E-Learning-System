<?php

use App\Domains\Comms\Jobs\ExportEngagementRegister;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\EngagementExport;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(EngagementExport::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('hides the download action until an export has been generated', function () {
    Livewire::test(EngagementExport::class)
        ->assertActionHidden('download');
});

it('generates the export and reveals the download action', function () {
    Livewire::test(EngagementExport::class)
        ->callAction('generate')
        ->assertNotified();

    expect(Storage::disk('local')->exists(ExportEngagementRegister::pathFor($this->tenant->id)))->toBeTrue();

    Livewire::test(EngagementExport::class)
        ->assertActionVisible('download');
});
