<?php

use App\Domains\Assignments\Models\Assignment;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Planner\Models\PlannerEvent;
use App\Domains\Planner\Services\PlannerAggregator;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Homework lesson', 'type' => 'assignment', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->viewer->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->from = now()->startOfDay();
    $this->to = now()->addDays(7)->endOfDay();
});

it('merges custom events, enrolled batch sessions, and assignment due dates for one user', function () {
    $customEvent = PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Study group', 'starts_at' => now()->addDay()]);
    $session = BatchSession::create(['batch_id' => $this->batch->id, 'starts_at' => now()->addDays(2), 'duration_minutes' => 60, 'position' => 0]);
    $assignment = Assignment::create(['lesson_id' => $this->lesson->id, 'title' => 'Problem set', 'due_at' => now()->addDays(3), 'max_marks' => 10, 'allowed_file_types' => ['pdf'], 'max_file_size_kb' => 2000]);

    $events = app(PlannerAggregator::class)->eventsFor($this->viewer, $this->from, $this->to);

    expect($events)->toHaveCount(3);
    expect($events->pluck('source')->all())->toBe(['custom', 'batch_session', 'assignment_due']);
    expect($events->firstWhere('source', 'custom')['id'])->toBe($customEvent->id);
    expect($events->firstWhere('source', 'batch_session')['id'])->toBe($session->id);
    expect($events->firstWhere('source', 'assignment_due')['id'])->toBe($assignment->id);
});

it('excludes a batch the viewer is not enrolled in and another user\'s own custom event', function () {
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    PlannerEvent::create(['user_id' => $otherUser->id, 'title' => 'Not mine', 'starts_at' => now()->addDay()]);

    $otherBatch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch B',
        'slug' => 'batch-b',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    BatchSession::create(['batch_id' => $otherBatch->id, 'starts_at' => now()->addDays(2), 'duration_minutes' => 60, 'position' => 0]);

    $events = app(PlannerAggregator::class)->eventsFor($this->viewer, $this->from, $this->to);

    expect($events)->toBeEmpty();
});

it('never leaks another tenant\'s data', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherTenantUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
    PlannerEvent::create(['user_id' => $otherTenantUser->id, 'title' => 'Other tenant', 'starts_at' => now()->addDay()]);
    app(CurrentTenant::class)->set($this->tenant);

    $events = app(PlannerAggregator::class)->eventsFor($this->viewer, $this->from, $this->to);

    expect($events)->toBeEmpty();
});

it('respects the given date range, excluding events outside it', function () {
    PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'Too far ahead', 'starts_at' => now()->addDays(30)]);
    $inRange = PlannerEvent::create(['user_id' => $this->viewer->id, 'title' => 'In range', 'starts_at' => now()->addDays(2)]);

    $events = app(PlannerAggregator::class)->eventsFor($this->viewer, $this->from, $this->to);

    expect($events)->toHaveCount(1);
    expect($events->first()['id'])->toBe($inRange->id);
});
