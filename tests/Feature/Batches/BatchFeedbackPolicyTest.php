<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Policies\BatchFeedbackPolicy;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->policy = new BatchFeedbackPolicy;
});

it('allows a student with an active enrolment', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->policy->canSubmit($this->student, $this->batch))->toBeTrue();
});

it('allows a student with an expired enrolment', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'expired',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->policy->canSubmit($this->student, $this->batch))->toBeTrue();
});

it('denies a student with a rejected enrolment', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'rejected',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
    ]);

    expect($this->policy->canSubmit($this->student, $this->batch))->toBeFalse();
});

it('denies a student with no enrolment at all', function () {
    expect($this->policy->canSubmit($this->student, $this->batch))->toBeFalse();
});
