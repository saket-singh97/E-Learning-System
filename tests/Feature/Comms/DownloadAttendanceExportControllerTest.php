<?php

use App\Domains\Comms\Jobs\ExportAttendanceRegister;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
});

it('404s when the export has not been generated yet', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/attendance-register')
        ->assertNotFound();
});

it('downloads the csv once it has been generated', function () {
    ExportAttendanceRegister::dispatch($this->tenant->id);

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/attendance-register')
        ->assertOk()
        ->assertDownload('attendance-register.csv');
});

it('blocks a guest', function () {
    ExportAttendanceRegister::dispatch($this->tenant->id);

    $this->get('http://physics.lms.test/exports/attendance-register')
        ->assertForbidden();
});

it('blocks a non-admin role', function () {
    ExportAttendanceRegister::dispatch($this->tenant->id);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/exports/attendance-register')
        ->assertForbidden();
});

it('blocks an institute admin from a different tenant', function () {
    ExportAttendanceRegister::dispatch($this->tenant->id);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($otherAdmin)
        ->get('http://physics.lms.test/exports/attendance-register')
        ->assertForbidden();
});
