<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\ListQuestions;
use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use App\Domains\Assessments\Models\Question;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(QuestionResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('promotes a lessons seed_questions into the question bank via the header action', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'seed_questions' => [[
            'type' => 'mcq',
            'stem' => 'What is velocity?',
            'options' => ['Speed', 'Speed with direction'],
            'answer' => 'Speed with direction',
            'explanation' => 'Velocity is a vector.',
            'concept' => 'Kinematics',
            'difficulty' => 'easy',
        ]],
    ]);

    Livewire::test(ListQuestions::class)
        ->callAction('generateWithAi', data: [
            'mode' => 'from_lesson',
            'lesson_id' => $this->lesson->id,
        ])
        ->assertHasNoActionErrors();

    $question = Question::sole();

    expect($question->stem)->toBe('What is velocity?')
        ->and($question->status)->toBe('draft')
        ->and($question->source)->toBe('ai_generated');
});

it('generates a custom blueprint via the header action', function () {
    Livewire::test(ListQuestions::class)
        ->callAction('generateWithAi', data: [
            'mode' => 'blueprint',
            'course_id' => $this->course->id,
            'blueprint' => [
                ['concept' => 'Motion', 'type' => 'mcq', 'difficulty' => 'easy', 'count' => 2],
            ],
        ])
        ->assertHasNoActionErrors();

    expect(Question::where('course_id', $this->course->id)->where('source', 'ai_generated')->count())->toBe(2);
});

it('requires a lesson to be picked in from_lesson mode', function () {
    Livewire::test(ListQuestions::class)
        ->callAction('generateWithAi', data: [
            'mode' => 'from_lesson',
        ])
        ->assertHasActionErrors(['lesson_id']);

    expect(Question::count())->toBe(0);
});
