<?php

use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\CreateQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\QuizResource;
use App\Domains\Assessments\Filament\Resources\TestSeries\Pages\CreateTestSeries;
use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\TestSeries;
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

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);
});

it('creates a test series from the Admin panel', function () {
    $this->actingAs($this->admin)
        ->get(TestSeriesResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(CreateTestSeries::class)
        ->fillForm([
            'title' => 'Weekly Mock Series',
            'starts_at' => '2026-09-01 09:00:00',
            'ends_at' => '2026-09-30 18:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $series = TestSeries::sole();

    expect($series->title)->toBe('Weekly Mock Series');
    expect($series->tenant_id)->toBe($this->tenant->id);
});

it('assigns a quiz to a test series through the Teach-panel QuizResource form', function () {
    $series = TestSeries::create(['title' => 'Weekly Mock Series']);

    $this->actingAs($this->instructor)
        ->get(QuizResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(CreateQuiz::class)
        ->fillForm([
            'lesson_id' => $this->lesson->id,
            'title' => 'Week 1 Mock',
            'blueprint' => [['count' => 1]],
            'time_limit_minutes' => 30,
            'max_attempts' => 1,
            'status' => 'draft',
            'test_series_id' => $series->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quiz = Quiz::sole();
    expect($quiz->test_series_id)->toBe($series->id);
});

it('leaves a quiz with no series assigned unaffected', function () {
    $this->actingAs($this->instructor)
        ->get(QuizResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(CreateQuiz::class)
        ->fillForm([
            'lesson_id' => $this->lesson->id,
            'title' => 'Standalone Quiz',
            'blueprint' => [['count' => 1]],
            'time_limit_minutes' => 30,
            'max_attempts' => 1,
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Quiz::sole()->test_series_id)->toBeNull();
});
