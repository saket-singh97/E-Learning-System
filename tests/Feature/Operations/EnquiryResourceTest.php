<?php

use App\Domains\Operations\Filament\Resources\Enquiries\EnquiryResource;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\CreateEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Domains\Operations\Models\Enquiry;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');
});

it('creates an enquiry from the Admin panel', function () {
    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateEnquiry::class)
        ->fillForm([
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'status' => 'new',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Enquiry::where('email', 'priya@example.com')->exists())->toBeTrue();
});

it('is also reachable from the Teach panel', function () {
    $this->actingAs($this->instructor)
        ->get(EnquiryResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(CreateEnquiry::class)
        ->fillForm(['name' => 'Ravi Shah', 'email' => 'ravi@example.com', 'status' => 'new'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Enquiry::where('email', 'ravi@example.com')->exists())->toBeTrue();
});

it('lists enquiries for the current tenant only', function () {
    $mine = Enquiry::create(['name' => 'Mine', 'email' => 'mine@example.com', 'status' => 'new']);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $theirs = Enquiry::create(['name' => 'Theirs', 'email' => 'theirs@example.com', 'status' => 'new']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($this->admin)
        ->get(EnquiryResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListEnquiries::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
