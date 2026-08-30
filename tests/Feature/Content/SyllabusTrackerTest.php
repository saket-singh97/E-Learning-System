<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\SyllabusTopic;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\SyllabusTracker;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Widgets\CourseSyllabusTrackerWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Mechanics', 'position' => 0]);
});

it('returns an empty array when no syllabus topics are mapped', function () {
    expect(app(SyllabusTracker::class)->percentCompleteFor($this->course, $this->student))->toBe([]);
});

it('does not divide by zero when a board_or_exam group has topics but no lessons mapped', function () {
    SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'JEE Main', 'title' => 'Unmapped', 'sequence' => 0]);

    expect(app(SyllabusTracker::class)->percentCompleteFor($this->course, $this->student))->toBe(['JEE Main' => 0.0]);
});

it('computes partial completion for a board_or_exam group', function () {
    $lessonA = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    $lessonB = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L2', 'type' => 'text', 'position' => 1]);

    $topic = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $topic->lessons()->sync([$lessonA->id, $lessonB->id]);

    VideoWatchEvent::create(['lesson_id' => $lessonA->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    expect(app(SyllabusTracker::class)->percentCompleteFor($this->course, $this->student))->toBe(['CBSE Class 10' => 50.0]);
});

it('groups completion independently by board_or_exam', function () {
    $lessonA = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    $lessonB = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L2', 'type' => 'text', 'position' => 1]);

    $cbse = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $cbse->lessons()->sync([$lessonA->id]);
    $jee = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'JEE Main', 'title' => 'Advanced Kinematics', 'sequence' => 1]);
    $jee->lessons()->sync([$lessonB->id]);

    VideoWatchEvent::create(['lesson_id' => $lessonA->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    expect(app(SyllabusTracker::class)->percentCompleteFor($this->course, $this->student))
        ->toBe(['CBSE Class 10' => 100.0, 'JEE Main' => 0.0]);
});

it('shows the syllabus tracker widget on the student-facing syllabus page', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    $topic = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $topic->lessons()->sync([$lesson->id]);
    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    $this->actingAs($this->student)
        ->get("http://physics.lms.test/courses/{$this->course->slug}")
        ->assertOk()
        ->assertSee('CBSE Class 10')
        ->assertSee('100.0% complete');
});

it('shows "not tracked" copy for a guest previewing a course with no syllabus mapped', function () {
    $this->get("http://physics.lms.test/courses/{$this->course->slug}")
        ->assertOk()
        ->assertSee('Syllabus not tracked yet');
});

it('course-level syllabus coverage widget shows the empty state with no students enrolled', function () {
    SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);

    Livewire::test(CourseSyllabusTrackerWidget::class)
        ->set('record', $this->course)
        ->assertSee('No students enrolled yet');
});

it('course-level syllabus coverage widget averages completion across enrolled students', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    $topic = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $topic->lessons()->sync([$lesson->id]);

    $batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    Livewire::test(CourseSyllabusTrackerWidget::class)
        ->set('record', $this->course)
        ->assertSee('CBSE Class 10')
        ->assertSee('100.0% complete');
});
