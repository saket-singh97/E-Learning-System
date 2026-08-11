<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->liveLesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Q&A',
        'type' => 'live_session',
        'position' => 0,
    ]);

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
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/room-1',
        'room_created_at' => now(),
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->joinUrl = fn (): string => "http://physics.lms.test/live-sessions/{$this->session->id}/join";
});

it('redirects an enrolled student to the meeting url during the live window', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    $this->actingAs($this->student)
        ->get(($this->joinUrl)())
        ->assertRedirect('https://meet.example.com/room-1');
});

it('lets staff join without any enrolment row', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    $this->actingAs($this->instructor)
        ->get(($this->joinUrl)())
        ->assertRedirect('https://meet.example.com/room-1');
});

it('403s a student with no active enrolment', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    $this->actingAs($this->student)
        ->get(($this->joinUrl)())
        ->assertForbidden();
});

it('403s an enrolled student before the session starts', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 09:00:00'));

    $this->actingAs($this->student)
        ->get(($this->joinUrl)())
        ->assertForbidden();
});

it('403s an enrolled student after the session window has closed', function () {
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 11:00:01'));

    $this->actingAs($this->student)
        ->get(($this->joinUrl)())
        ->assertForbidden();
});

it('403s a guest', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    $this->get(($this->joinUrl)())->assertForbidden();
});

it('404s a session that is not linked to any lesson', function () {
    $plainSession = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => '2026-09-08 10:00:00',
        'duration_minutes' => 60,
        'position' => 1,
    ]);

    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    $this->actingAs($this->instructor)
        ->get("http://physics.lms.test/live-sessions/{$plainSession->id}/join")
        ->assertNotFound();
});

it('404s a nonexistent session id', function () {
    $this->actingAs($this->instructor)
        ->get('http://physics.lms.test/live-sessions/999999/join')
        ->assertNotFound();
});
