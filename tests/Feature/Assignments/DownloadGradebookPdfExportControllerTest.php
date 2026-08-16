<?php

use App\Domains\Assignments\Jobs\ExportGradebookPdf;
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
        ->get('http://physics.lms.test/exports/gradebook.pdf')
        ->assertNotFound();
});

it('downloads the pdf once it has been generated', function () {
    ExportGradebookPdf::dispatch($this->tenant->id);

    $this->actingAs($this->admin)
        ->get('http://physics.lms.test/exports/gradebook.pdf')
        ->assertOk()
        ->assertDownload('gradebook.pdf');
});

it('blocks a student', function () {
    ExportGradebookPdf::dispatch($this->tenant->id);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/exports/gradebook.pdf')
        ->assertForbidden();
});
