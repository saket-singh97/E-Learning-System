<?php

use App\Domains\Assignments\Jobs\ExportGradebookCsv;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');
});

it('404s when the export has not been generated yet', function () {
    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/gradebook')
        ->assertNotFound();
});

it('lets an institute admin download the csv once it has been generated', function () {
    ExportGradebookCsv::dispatch($this->tenant->id);

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/gradebook')
        ->assertOk()
        ->assertDownload('gradebook.csv');
});

it('lets an instructor download the same export, unlike the admin-only engagement/attendance exports', function () {
    ExportGradebookCsv::dispatch($this->tenant->id);

    $this->actingAs($this->instructor)
        ->get('http://physics.lms.test/exports/gradebook')
        ->assertOk()
        ->assertDownload('gradebook.csv');
});

it('blocks a guest', function () {
    ExportGradebookCsv::dispatch($this->tenant->id);

    $this->get('http://physics.lms.test/exports/gradebook')
        ->assertForbidden();
});

it('blocks a student', function () {
    ExportGradebookCsv::dispatch($this->tenant->id);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/exports/gradebook')
        ->assertForbidden();
});

it('blocks an instructor from a different tenant', function () {
    ExportGradebookCsv::dispatch($this->tenant->id);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherInstructor = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherInstructor->assignRole('instructor');
    app(CurrentTenant::class)->set($this->tenant);

    $this->actingAs($otherInstructor)
        ->get('http://physics.lms.test/exports/gradebook')
        ->assertForbidden();
});
