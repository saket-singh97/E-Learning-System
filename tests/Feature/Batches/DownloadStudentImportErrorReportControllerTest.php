<?php

use App\Domains\Batches\Models\StudentImportBatch;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->importBatch = StudentImportBatch::create([
        'uploaded_by_user_id' => $this->admin->id,
        'status' => 'done',
        'total_rows' => 1,
        'error_count' => 1,
    ]);
});

it('404s before the report has been generated', function () {
    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/student-imports/{$this->importBatch->id}/error-report")
        ->assertNotFound();
});

it('downloads the report once generated', function () {
    Storage::disk('local')->put("student-imports/{$this->importBatch->id}-errors.csv", "Row,Error\n2,Name is required.");
    $this->importBatch->update(['error_report_disk_path' => "student-imports/{$this->importBatch->id}-errors.csv"]);

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/student-imports/{$this->importBatch->id}/error-report")
        ->assertOk()
        ->assertDownload();
});

it('forbids a non-admin from downloading', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/student-imports/{$this->importBatch->id}/error-report")
        ->assertForbidden();
});

it('forbids an institute_admin from a different tenant', function () {
    Storage::disk('local')->put("student-imports/{$this->importBatch->id}-errors.csv", "Row,Error\n2,Name is required.");
    $this->importBatch->update(['error_report_disk_path' => "student-imports/{$this->importBatch->id}-errors.csv"]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/student-imports/{$this->importBatch->id}/error-report")
        ->assertForbidden();
});
