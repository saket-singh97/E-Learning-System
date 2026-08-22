<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Policies\EnrolmentApprovalPolicy;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $this->tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);

    app(CurrentTenant::class)->set($this->tenantA);
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_approval',
        'status' => 'published',
    ]);

    $this->policy = new EnrolmentApprovalPolicy;
});

it('allows an institute_admin in the batch tenant to decide', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $admin->assignRole('institute_admin');

    expect($this->policy->canDecide($admin, $this->batch))->toBeTrue();
});

it('allows the batch own assigned instructor to decide', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $instructor->assignRole('instructor');
    $this->batch->update(['instructor_id' => $instructor->id]);

    expect($this->policy->canDecide($instructor, $this->batch))->toBeTrue();
});

it('denies a same-tenant instructor who is not assigned to this batch', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $instructor->assignRole('instructor');

    expect($this->policy->canDecide($instructor, $this->batch))->toBeFalse();
});

it('denies an instructor from a different tenant', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenantB->id]);
    $instructor->assignRole('instructor');

    expect($this->policy->canDecide($instructor, $this->batch))->toBeFalse();
});

it('denies a student in the same tenant', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $student->assignRole('student');

    expect($this->policy->canDecide($student, $this->batch))->toBeFalse();
});
