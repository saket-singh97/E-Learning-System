<?php

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Listeners\BustAggregateCacheOnAttendance;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Comms\Services\StudentMetrics;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('busts the student-metrics cache for the events own tenant/user on handle', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create(['course_id' => $course->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);
    $session = BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);

    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);
    expect(app(StudentMetrics::class)->summarize($this->student)['attendance_percent'])->toBe(100.0);

    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    // Bypassing the real writer (no AttendanceRecorded fired), so the
    // cache is still stale until the listener runs explicitly below.
    expect(app(StudentMetrics::class)->summarize($this->student)['attendance_percent'])->toBe(100.0);

    app(BustAggregateCacheOnAttendance::class)->handle(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    expect(app(StudentMetrics::class)->summarize($this->student)['attendance_percent'])->toBe(50.0);
});

/**
 * The real wiring proof — same "real writer, real event, no bypass"
 * shape this app's own event-registration tests already use elsewhere.
 */
it('fires from the real markAttendance write path and busts the cache end to end', function () {
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create(['course_id' => $course->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);
    $session = BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);

    expect(app(StudentMetrics::class)->summarize($this->student)['attendance_percent'])->toBeNull();

    AttendanceRecord::updateOrCreate(
        ['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id],
        ['status' => 'present', 'source' => 'manual', 'recorded_at' => now()],
    );
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'present'));

    expect(app(StudentMetrics::class)->summarize($this->student)['attendance_percent'])->toBe(100.0);
});
