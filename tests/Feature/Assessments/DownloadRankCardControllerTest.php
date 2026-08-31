<?php

use App\Domains\Assessments\Models\RankCard;
use App\Domains\Assessments\Models\TestSeries;
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

    $this->series = TestSeries::create(['title' => 'Weekly Mock']);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->rankCard = RankCard::create([
        'test_series_id' => $this->series->id,
        'user_id' => $this->student->id,
        'pdf_path' => "rank-cards/{$this->tenant->id}/{$this->series->id}/{$this->student->id}.pdf",
    ]);
    Storage::disk('local')->put($this->rankCard->pdf_path, '%PDF-bytes');
});

it('404s before the pdf file exists on disk', function () {
    $this->rankCard->update(['pdf_path' => 'rank-cards/missing.pdf']);

    $this->actingAs($this->student)
        ->get("http://physics.lms.test/rank-cards/{$this->rankCard->id}/download")
        ->assertNotFound();
});

it('allows the owning student to download their own rank card', function () {
    $this->actingAs($this->student)
        ->get("http://physics.lms.test/rank-cards/{$this->rankCard->id}/download")
        ->assertOk()
        ->assertDownload();
});

it('forbids a different student from downloading', function () {
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStudent->assignRole('student');

    $this->actingAs($otherStudent)
        ->get("http://physics.lms.test/rank-cards/{$this->rankCard->id}/download")
        ->assertForbidden();
});

it('allows this tenant\'s institute_admin to download any student\'s rank card', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get("http://physics.lms.test/rank-cards/{$this->rankCard->id}/download")
        ->assertOk()
        ->assertDownload();
});

it('forbids an institute_admin from a different tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/rank-cards/{$this->rankCard->id}/download")
        ->assertForbidden();
});
