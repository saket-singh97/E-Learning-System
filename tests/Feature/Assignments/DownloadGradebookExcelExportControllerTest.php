<?php

use App\Domains\Assignments\Jobs\ExportGradebookExcel;
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
        ->get('http://physics.lms.test/exports/gradebook.xlsx')
        ->assertNotFound();
});

it('downloads the xlsx once it has been generated', function () {
    ExportGradebookExcel::dispatch($this->tenant->id);

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/gradebook.xlsx')
        ->assertOk()
        ->assertDownload('gradebook.xlsx');
});

it('blocks a student', function () {
    ExportGradebookExcel::dispatch($this->tenant->id);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/exports/gradebook.xlsx')
        ->assertForbidden();
});
