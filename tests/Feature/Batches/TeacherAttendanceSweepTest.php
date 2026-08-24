<?php

use App\Domains\Batches\Jobs\TeacherAttendanceSweep;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('finds a past-end session with no instructor attendance row', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();
    $found = app(TeacherAttendanceSweep::class)->unmarkedSessions();

    expect($found->pluck('id')->all())->toBe([$session->id]);
});

it('skips a past-end session that already has an instructor attendance row', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    AttendanceRecord::create([
        'attendable_type' => BatchSession::class,
        'attendable_id' => $session->id,
        'user_id' => $this->instructor->id,
        'role' => 'instructor',
        'status' => 'present',
        'source' => 'manual',
        'recorded_at' => now(),
    ]);

    app(CurrentTenant::class)->clear();
    $found = app(TeacherAttendanceSweep::class)->unmarkedSessions();

    expect($found->pluck('id')->all())->not->toContain($session->id);
});

it('skips a session that has not ended yet', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->addHour(),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();
    $found = app(TeacherAttendanceSweep::class)->unmarkedSessions();

    expect($found->pluck('id')->all())->not->toContain($session->id);
});

it('ignores a student attendance row for the same session — only instructor role counts', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    AttendanceRecord::create([
        'attendable_type' => BatchSession::class,
        'attendable_id' => $session->id,
        'user_id' => $student->id,
        'role' => 'student',
        'status' => 'present',
        'source' => 'manual',
        'recorded_at' => now(),
    ]);

    app(CurrentTenant::class)->clear();
    $found = app(TeacherAttendanceSweep::class)->unmarkedSessions();

    expect($found->pluck('id')->all())->toBe([$session->id]);
});

it('handle() runs unscoped across tenants without a bound CurrentTenant', function () {
    BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();

    app(TeacherAttendanceSweep::class)->handle();

    expect(true)->toBeTrue();
});
