<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\IdCardGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
});

it('404s before an id card has been issued', function () {
    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertNotFound();
});

it('downloads the id card once issued', function () {
    (new IdCardGenerator)->generate($this->enrolment);

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertOk()
        ->assertDownload();
});

it('forbids a student from downloading', function () {
    (new IdCardGenerator)->generate($this->enrolment);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertForbidden();
});

it('forbids an instructor not assigned to this enrolment\'s batch', function () {
    (new IdCardGenerator)->generate($this->enrolment);

    $otherInstructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherInstructor->assignRole('instructor');

    $this->actingAs($otherInstructor)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertForbidden();
});

it('allows the enrolment\'s own assigned instructor to download', function () {
    (new IdCardGenerator)->generate($this->enrolment);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');
    $this->enrolment->batch->update(['instructor_id' => $instructor->id]);

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertOk()
        ->assertDownload();
});

it('forbids an institute_admin from a different tenant', function () {
    (new IdCardGenerator)->generate($this->enrolment);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/id-cards/{$this->enrolment->id}/download")
        ->assertForbidden();
});
