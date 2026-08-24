<?php

use App\Domains\Batches\Jobs\TeacherAttendanceSweep;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TeacherAttendanceFlag;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\TeacherAttendanceFlagged;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

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

it('handle() creates an open flag for a detected gap and notifies every institute_admin', function () {
    Notification::fake();

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();
    app(TeacherAttendanceSweep::class)->handle();

    app(CurrentTenant::class)->set($this->tenant);
    $flag = TeacherAttendanceFlag::where('batch_session_id', $session->id)->sole();

    expect($flag->status)->toBe('open');
    expect($flag->instructor_id)->toBe($this->instructor->id);
    expect($flag->tenant_id)->toBe($this->tenant->id);

    Notification::assertSentTo($admin, TeacherAttendanceFlagged::class, fn (TeacherAttendanceFlagged $notification) => $notification->flag->id === $flag->id);
});

it('does not re-flag a session that already has a flag, even a resolved one', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    TeacherAttendanceFlag::create([
        'batch_session_id' => $session->id,
        'instructor_id' => $this->instructor->id,
        'flagged_at' => now()->subDay(),
        'status' => 'resolved',
    ]);

    app(CurrentTenant::class)->clear();
    app(TeacherAttendanceSweep::class)->handle();

    app(CurrentTenant::class)->set($this->tenant);
    expect(TeacherAttendanceFlag::where('batch_session_id', $session->id)->count())->toBe(1);
});

it('does not flag a session whose batch has no assigned instructor', function () {
    $course = Course::create(['title' => 'No Instructor', 'slug' => 'no-instructor-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Unassigned Batch',
        'slug' => 'unassigned-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    app(CurrentTenant::class)->clear();
    app(TeacherAttendanceSweep::class)->handle();

    app(CurrentTenant::class)->set($this->tenant);
    expect(TeacherAttendanceFlag::where('batch_session_id', $session->id)->count())->toBe(0);
});
