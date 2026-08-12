<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->course = $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->liveLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);
    $this->videoLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 1]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'lesson_id' => $this->liveLesson->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->student->assignRole('student');

    $this->access = app(LessonAccess::class);
});

it('resolves the batch session for a viewer with an active enrolment in its batch', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $resolved = $this->access->myLiveBatchSession($this->liveLesson, $this->student);

    expect($resolved?->id)->toBe($this->session->id);
});

it('returns null for a viewer with no enrolment at all', function () {
    expect($this->access->myLiveBatchSession($this->liveLesson, $this->student))->toBeNull();
});

it('returns null for a viewer whose enrolment in that batch is not active', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
    ]);

    expect($this->access->myLiveBatchSession($this->liveLesson, $this->student))->toBeNull();
});

it('returns null for a non-live_session lesson', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($this->access->myLiveBatchSession($this->videoLesson, $this->student))->toBeNull();
});

it('returns null for a guest viewer', function () {
    expect($this->access->myLiveBatchSession($this->liveLesson, null))->toBeNull();
});

it('resolves the soonest session by starts_at when the viewer has more than one active candidate', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $earlierBatch = Batch::create([
        'course_id' => $this->batch->course_id,
        'title' => 'Early Batch',
        'slug' => 'early-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    Enrolment::create([
        'batch_id' => $earlierBatch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    // Inserted after $this->session (which starts at 10:00) but starts
    // earlier — only an explicit orderBy('starts_at') picks this one;
    // relying on insertion/row order would return $this->session instead.
    $earlierSession = BatchSession::create([
        'batch_id' => $earlierBatch->id,
        'lesson_id' => $this->liveLesson->id,
        'starts_at' => '2026-09-08 09:00:00',
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    $resolved = $this->access->myLiveBatchSession($this->liveLesson, $this->student);

    expect($resolved?->id)->toBe($earlierSession->id);
});

it('canJoinLive: allows a student with an active enrolment in the sessions batch during the live window', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $this->student))->toBeTrue();
});

it('canJoinLive: refuses a student with no active enrolment', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $this->student))->toBeFalse();
});

it('canJoinLive: bypasses the enrolment check for staff during the live window', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->student->tenant_id]);
    $instructor->assignRole('instructor');

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $instructor))->toBeTrue();
});

it('canJoinLive: refuses when the session does not belong to the given lesson', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->videoLesson, $this->session, $this->student))->toBeFalse();
});

it('canJoinLive: refuses a guest', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, null))->toBeFalse();
});

it('canJoinLive: refuses an enrolled student once the course has since been unpublished', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->course->update(['status' => 'draft']);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $this->student))->toBeFalse();
});

it('canJoinLive: still lets staff bypass an unpublished course, same posture as isVisibleTo()', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->student->tenant_id]);
    $instructor->assignRole('instructor');

    $this->course->update(['status' => 'draft']);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $instructor))->toBeTrue();
});

it('canJoinLive: refuses staff outside the live window despite the staff bypass', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->student->tenant_id]);
    $instructor->assignRole('instructor');

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 09:00:00'));

    expect($this->access->canJoinLive($this->liveLesson, $this->session, $instructor))->toBeFalse();
});
