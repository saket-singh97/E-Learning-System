<?php

use App\Domains\Assessments\Filament\Resources\TestSeries\Pages\EditTestSeries;
use App\Domains\Assessments\Filament\Resources\TestSeries\RelationManagers\RankCardsRelationManager;
use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\RankCard;
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

    $this->series = TestSeries::create(['title' => 'Weekly Mock']);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Mock 1',
        'blueprint' => [],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
        'test_series_id' => $this->series->id,
    ]);

    $this->studentA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->studentA->assignRole('student');
    QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->studentA->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => 6,
        'max_score' => 10,
    ]);

    $this->studentB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->studentB->assignRole('student');
    QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->studentB->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => 9,
        'max_score' => 10,
    ]);

    $this->actingAs($this->admin)
        ->get(TestSeriesResource::getUrl('edit', ['record' => $this->series, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('generates a rank card for every participant via the header action, tenant-scoped', function () {
    Livewire::actingAs($this->admin)
        ->test(RankCardsRelationManager::class, [
            'ownerRecord' => $this->series,
            'pageClass' => EditTestSeries::class,
        ])->callTableAction('generateAll');

    expect(RankCard::where('test_series_id', $this->series->id)->count())->toBe(2);
    expect(RankCard::where('test_series_id', $this->series->id)->where('user_id', $this->studentA->id)->exists())->toBeTrue();
    expect(RankCard::where('test_series_id', $this->series->id)->where('user_id', $this->studentB->id)->exists())->toBeTrue();
});

it('lists generated rank cards scoped to this series', function () {
    Livewire::actingAs($this->admin)
        ->test(RankCardsRelationManager::class, [
            'ownerRecord' => $this->series,
            'pageClass' => EditTestSeries::class,
        ])->callTableAction('generateAll');

    $cards = RankCard::where('test_series_id', $this->series->id)->get();

    Livewire::actingAs($this->admin)
        ->test(RankCardsRelationManager::class, [
            'ownerRecord' => $this->series,
            'pageClass' => EditTestSeries::class,
        ])->assertCanSeeTableRecords($cards);
});
