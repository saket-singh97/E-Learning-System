<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Pages\Gradebook;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->actingAs($this->instructor)
        ->get(Gradebook::getUrl(['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

// Prefixed to avoid colliding with StudentProgressPageTest's own bare
// "enrolStudent" helper — Pest loads every test file's global functions
// into one shared namespace (found the hard way in S10 B2).
function enrolStudentForGradebookTable(Tenant $tenant, ?Course $course = null): array
{
    $course ??= Course::create(['title' => 'Physics 101', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    return ['course' => $course, 'student' => $student, 'enrolment' => $enrolment, 'batch' => $batch];
}

it('lists actively enrolled students with a placeholder when there is no gradebook data yet', function () {
    ['student' => $student] = enrolStudentForGradebookTable($this->tenant);

    Livewire::test(Gradebook::class)
        ->assertSee($student->name)
        ->assertSee('—');
});

it('excludes a student with no active enrolment', function () {
    ['student' => $active, 'enrolment' => $activeEnrolment] = enrolStudentForGradebookTable($this->tenant);
    ['student' => $waitlisted] = enrolStudentForGradebookTable($this->tenant);
    Enrolment::where('user_id', $waitlisted->id)->update(['status' => 'waitlisted']);

    Livewire::test(Gradebook::class)
        ->assertCanSeeTableRecords([$activeEnrolment])
        ->assertSee($active->name)
        ->assertDontSee($waitlisted->name);
});

it('excludes an enrolment from a different tenant', function () {
    ['student' => $active, 'enrolment' => $activeEnrolment] = enrolStudentForGradebookTable($this->tenant);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    enrolStudentForGradebookTable($otherTenant);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(Gradebook::class)
        ->assertCanSeeTableRecords([$activeEnrolment]);
});

it('filters the table down to one course', function () {
    $courseA = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $courseB = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);

    ['student' => $studentA] = enrolStudentForGradebookTable($this->tenant, $courseA);
    ['student' => $studentB] = enrolStudentForGradebookTable($this->tenant, $courseB);

    Livewire::test(Gradebook::class)
        ->filterTable('course', $courseA->id)
        ->assertSee($studentA->name)
        ->assertDontSee($studentB->name);
});

it('shows Failing for a student below the course own passing threshold', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published', 'passing_threshold' => 40]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3]);

    ['student' => $student] = enrolStudentForGradebookTable($this->tenant, $course);

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 2, 'max_score' => 10,
    ]);

    Livewire::test(Gradebook::class)
        ->assertSee('Failing');
});

it('shows On track for a student passing and clear of the at-risk threshold', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3]);

    ['student' => $student] = enrolStudentForGradebookTable($this->tenant, $course);

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);

    Livewire::test(Gradebook::class)
        ->assertSee('On track');
});

it('excludes an enrolment whose batch was soft-deleted instead of crashing the table', function () {
    // S10 B15 review pass — same BuildsGradebookRows fix the CSV/Excel/PDF
    // exports and the certificate sweep job also needed. Batch has no
    // delete guard against active enrolments, so a soft-deleted batch can
    // still have one pointing at it.
    ['student' => $orphaned, 'batch' => $orphanedBatch] = enrolStudentForGradebookTable($this->tenant);
    ['student' => $normal] = enrolStudentForGradebookTable($this->tenant);

    $orphanedBatch->delete();

    Livewire::test(Gradebook::class)
        ->assertSee($normal->name)
        ->assertDontSee($orphaned->name);
});
