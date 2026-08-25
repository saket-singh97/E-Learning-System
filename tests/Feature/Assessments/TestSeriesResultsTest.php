<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\TestSeriesResults;
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

    $this->series = TestSeries::create(['title' => 'Weekly Mock']);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Mock 1',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
        'status' => 'published',
        'test_series_id' => $this->series->id,
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => 6,
        'max_score' => 10,
    ]);

    $this->classmate = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Classmate Name']);
    $this->classmate->assignRole('student');

    QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->classmate->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => 9,
        'max_score' => 10,
    ]);
});

it('shows the viewer their own rank and percentile', function () {
    Livewire::actingAs($this->student)
        ->test(TestSeriesResults::class, ['series' => $this->series])
        ->assertSee('rank: 2 / 2')
        ->assertSee('0%');
});

it('never shows a classmates name — own standing only, not the full roster', function () {
    Livewire::actingAs($this->student)
        ->test(TestSeriesResults::class, ['series' => $this->series])
        ->assertDontSee('Classmate Name');
});

it('shows a not-yet-attempted message rather than a rank for a student with no submitted attempt', function () {
    $notYetAttempted = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $notYetAttempted->assignRole('student');

    Livewire::actingAs($notYetAttempted)
        ->test(TestSeriesResults::class, ['series' => $this->series])
        ->assertSee('submitted any attempt');
});
