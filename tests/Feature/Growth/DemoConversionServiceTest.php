<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Growth\Models\CourseInterestSignup;
use App\Domains\Growth\Services\DemoConversionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
    $this->service = app(DemoConversionService::class);
});

it('reports converted once a real enrolment exists for the matching email and course', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $signup = CourseInterestSignup::create(['course_id' => $course->id, 'name' => 'Prospect', 'email' => 'prospect@example.com']);

    expect($this->service->hasConverted($signup))->toBeFalse();

    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'prospect@example.com']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->service->hasConverted($signup))->toBeTrue();
});

it('does not match a same-email signup for a different course', function () {
    $signupCourse = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $enrolledCourse = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'published']);
    $signup = CourseInterestSignup::create(['course_id' => $signupCourse->id, 'name' => 'Prospect', 'email' => 'prospect@example.com']);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'prospect@example.com']);
    $batch = Batch::create([
        'course_id' => $enrolledCourse->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->service->hasConverted($signup))->toBeFalse();
});

it('does not match when the prospect enrols under a different email than they signed up with', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $signup = CourseInterestSignup::create(['course_id' => $course->id, 'name' => 'Prospect', 'email' => 'personal@example.com']);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'work@example.com']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->service->hasConverted($signup))->toBeFalse();
});
