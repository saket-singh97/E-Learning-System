<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->makeSession = fn (array $overrides = []): BatchSession => BatchSession::create(array_merge([
        'batch_id' => $batch->id,
        'lesson_id' => $this->lesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
    ], $overrides));
});

it('is scheduled before the start time', function () {
    $session = ($this->makeSession)();

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 09:59:59'));

    expect($session->liveState())->toBe(BatchSession::STATE_SCHEDULED);
});

it('is live at the exact start time', function () {
    $session = ($this->makeSession)();

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:00:00'));

    expect($session->liveState())->toBe(BatchSession::STATE_LIVE);
});

it('is live at the exact end-of-window boundary', function () {
    $session = ($this->makeSession)();

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 11:00:00'));

    expect($session->liveState())->toBe(BatchSession::STATE_LIVE);
});

it('is ended just after the window closes with no ready recording', function () {
    $session = ($this->makeSession)();

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 11:00:01'));

    expect($session->liveState())->toBe(BatchSession::STATE_ENDED);
});

it('is recorded once the window has closed and the linked lesson has a ready video', function () {
    $this->lesson->update(['video_status' => 'ready']);
    $session = ($this->makeSession)();

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 11:00:01'));

    expect($session->liveState())->toBe(BatchSession::STATE_RECORDED);
});

it('is ended, not recorded, for a plain timetable entry with no linked lesson', function () {
    $session = ($this->makeSession)(['lesson_id' => null]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 11:00:01'));

    expect($session->liveState())->toBe(BatchSession::STATE_ENDED);
});
