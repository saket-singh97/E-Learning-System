<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * S23 B9 (U18, design doc §4 decision 4) — same two-key shape
 * FeeGraceAccessGateTest already covers for the fee restriction:
 * tenant-level flag unlocks the feature, batch-level number carries the
 * actual threshold.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->access = app(LessonAccess::class);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonths(3)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    // 5 sessions: statuses set per test to whatever % it needs.
    $this->markAttendance = function (array $statuses): void {
        foreach ($statuses as $status) {
            $session = BatchSession::create([
                'batch_id' => $this->batch->id,
                'starts_at' => now()->subDay(),
                'duration_minutes' => 60,
                'position' => 0,
            ]);

            AttendanceRecord::create([
                'attendable_type' => BatchSession::class,
                'attendable_id' => $session->id,
                'user_id' => $this->student->id,
                'role' => 'student',
                'status' => $status,
                'source' => 'manual',
                'recorded_at' => now(),
            ]);
        }
    };
});

it('never blocks streaming when the tenant has not enabled attendance-linked access', function () {
    $this->batch->update(['min_attendance_percent' => 90]);
    ($this->markAttendance)(['absent', 'absent', 'absent', 'absent', 'present']);

    expect($this->access->canStream($this->lesson, $this->student))->toBeTrue();
});

it('exempts a batch with no attendance threshold set even when the tenant flag is on', function () {
    $this->tenant->update(['attendance_linked_access_enabled' => true]);
    ($this->markAttendance)(['absent', 'absent', 'absent', 'absent', 'present']);

    expect($this->access->canStream($this->lesson, $this->student))->toBeTrue();
});

it('blocks streaming when the viewer is below the batchs attendance threshold', function () {
    $this->tenant->update(['attendance_linked_access_enabled' => true]);
    $this->batch->update(['min_attendance_percent' => 75]);
    ($this->markAttendance)(['absent', 'absent', 'absent', 'absent', 'present']);

    expect($this->access->canStream($this->lesson, $this->student))->toBeFalse();
});

it('still allows streaming when the viewer meets the batchs attendance threshold', function () {
    $this->tenant->update(['attendance_linked_access_enabled' => true]);
    $this->batch->update(['min_attendance_percent' => 75]);
    ($this->markAttendance)(['present', 'present', 'present', 'present', 'absent']);

    expect($this->access->canStream($this->lesson, $this->student))->toBeTrue();
});

it('does not block a viewer with no attendance rows yet', function () {
    $this->tenant->update(['attendance_linked_access_enabled' => true]);
    $this->batch->update(['min_attendance_percent' => 75]);

    expect($this->access->canStream($this->lesson, $this->student))->toBeTrue();
});
