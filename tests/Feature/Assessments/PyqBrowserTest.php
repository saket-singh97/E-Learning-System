<?php

use App\Domains\Assessments\Livewire\PyqBrowser;
use App\Domains\Assessments\Models\Question;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
    Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->kinematics = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'A ball is dropped, find velocity.',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'concept' => 'Kinematics',
        'status' => 'approved',
        'source' => 'manual',
        'is_pyq' => true,
        'pyq_exam_name' => 'JEE Main',
        'pyq_exam_year' => 2023,
    ]);

    $this->thermo = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Find entropy change of the gas.',
        'correct_answer' => ['B'],
        'difficulty' => 'medium',
        'concept' => 'Thermodynamics',
        'status' => 'approved',
        'source' => 'manual',
        'is_pyq' => true,
        'pyq_exam_name' => 'NEET',
        'pyq_exam_year' => 2022,
    ]);

    // Draft PYQ — not yet approved, must stay hidden from the browse page
    // same as every other question-bank view (QuizAttemptService's own
    // approved-only pool).
    Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Unapproved draft PYQ.',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'concept' => 'Kinematics',
        'status' => 'draft',
        'source' => 'manual',
        'is_pyq' => true,
    ]);
});

it('lists approved PYQ questions for an enrolled student', function () {
    Livewire::actingAs($this->student)
        ->test(PyqBrowser::class, ['course' => $this->course])
        ->assertSee('A ball is dropped, find velocity.')
        ->assertSee('Find entropy change of the gas.')
        ->assertDontSee('Unapproved draft PYQ.');
});

it('filters by concept', function () {
    Livewire::actingAs($this->student)
        ->test(PyqBrowser::class, ['course' => $this->course])
        ->set('concept', 'Kinematics')
        ->assertSee('A ball is dropped, find velocity.')
        ->assertDontSee('Find entropy change of the gas.');
});

it('filters by exam name and year', function () {
    Livewire::actingAs($this->student)
        ->test(PyqBrowser::class, ['course' => $this->course])
        ->set('examName', 'NEET')
        ->set('examYear', 2022)
        ->assertSee('Find entropy change of the gas.')
        ->assertDontSee('A ball is dropped, find velocity.');
});

it('hides a PYQ seeded from a lesson the viewer cannot yet stream', function () {
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);

    $gate = Lesson::create(['course_section_id' => $section->id, 'title' => 'Intro', 'type' => 'text', 'text_body' => 'x', 'position' => 0]);
    $locked = Lesson::create(['course_section_id' => $section->id, 'title' => 'Advanced', 'type' => 'text', 'text_body' => 'x', 'position' => 1]);
    $locked->prerequisites()->attach($gate->id);

    Question::create([
        'course_id' => $this->course->id,
        'lesson_id' => $locked->id,
        'type' => 'mcq',
        'stem' => 'Locked-lesson PYQ.',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'status' => 'approved',
        'source' => 'manual',
        'is_pyq' => true,
    ]);

    Livewire::actingAs($this->student)
        ->test(PyqBrowser::class, ['course' => $this->course])
        ->assertDontSee('Locked-lesson PYQ.');
});

it('404s a non-enrolled user', function () {
    $stranger = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $stranger->assignRole('student');

    Livewire::actingAs($stranger)
        ->test(PyqBrowser::class, ['course' => $this->course])
        ->assertStatus(404);
});
