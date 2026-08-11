<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\SyllabusTree;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->videoLesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
    ]);

    $this->liveLesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Live Q&A',
        'type' => 'live_session',
        'position' => 1,
    ]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
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

    $this->quizLesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Kinematics Quiz',
        'type' => 'quiz',
        'position' => 2,
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->enrolActive = function (): Enrolment {
        return Enrolment::create([
            'batch_id' => $this->batch->id,
            'user_id' => $this->student->id,
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);
    };
});

it('renders course sections and lessons the viewer can stream, with a watch link', function () {
    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Physics 101')
        ->assertSee('Kinematics')
        ->assertSee('Velocity')
        ->assertSee("/lessons/{$this->videoLesson->id}", false);
});

it('hides a lesson the viewer cannot see', function () {
    $this->videoLesson->update(['scheduled_at' => now()->addDay()]);

    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertDontSee('Velocity');
});

it('shows a live_session lesson with no matching enrolment as unoffered, with no Join link', function () {
    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Live Q&A')
        ->assertSee('Not offered in your batch')
        ->assertDontSee('live-sessions', false);
});

it('shows the scheduled state for an enrolled student before the session starts', function () {
    ($this->enrolActive)();
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 09:00:00'));

    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Scheduled');
});

it('shows a Join link to the join endpoint during the live window for an enrolled student', function () {
    ($this->enrolActive)();
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 10:30:00'));

    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Live now')
        ->assertSee("/live-sessions/{$this->session->id}/join", false);
});

it('does not show a Join link outside the live window even for an enrolled student', function () {
    ($this->enrolActive)();
    $this->travelTo(Carbon\Carbon::parse('2026-09-08 09:00:00'));

    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertDontSee('live-sessions', false);
});

it('shows a quiz-type lesson with no Quiz built yet as not available, with no link', function () {
    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Kinematics Quiz')
        ->assertSee('Not yet available')
        ->assertDontSee('/quizzes/', false);
});

it('shows a Take quiz link once a published Quiz exists for the lesson', function () {
    $quiz = Quiz::create([
        'lesson_id' => $this->quizLesson->id,
        'title' => 'Kinematics Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 2,
        'status' => 'published',
    ]);

    Livewire::actingAs($this->student)
        ->test(SyllabusTree::class, ['course' => $this->course])
        ->assertSee('Take quiz')
        ->assertSee("/quizzes/{$quiz->id}", false);
});

it('serves the syllabus page for a course by slug', function () {
    $this->actingAs($this->student)
        ->get('http://physics.lms.test/courses/physics-101')
        ->assertOk()
        ->assertSee('Physics 101');
});

it('404s a draft courses syllabus page for a guest instead of leaking its title', function () {
    $this->course->update(['status' => 'draft', 'title' => 'SECRET DRAFT COURSE']);

    $this->get('http://physics.lms.test/courses/physics-101')
        ->assertNotFound()
        ->assertDontSee('SECRET DRAFT COURSE');
});

it('404s a draft courses syllabus page for a student', function () {
    $this->course->update(['status' => 'draft']);

    $this->actingAs($this->student)
        ->get('http://physics.lms.test/courses/physics-101')
        ->assertNotFound();
});

it('lets an instructor preview their own draft courses syllabus page', function () {
    $this->course->update(['status' => 'draft']);

    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/courses/physics-101')
        ->assertOk()
        ->assertSee('Physics 101');
});
