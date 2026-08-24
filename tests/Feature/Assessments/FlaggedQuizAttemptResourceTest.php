<?php

use App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\FlaggedQuizAttemptResource;
use App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\Pages\ListFlaggedQuizAttempts;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
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

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);
    $this->question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 3,
        'status' => 'published',
        'proctoring_enabled' => true,
    ]);

    $this->actingAs($this->instructor)
        ->get(FlaggedQuizAttemptResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('lists a flagged attempt but not an unflagged one', function () {
    $flagged = QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$this->question->id],
        'status' => 'auto_submitted',
        'started_at' => now()->subMinutes(10),
        'expires_at' => now()->subMinutes(5),
        'submitted_at' => now()->subMinutes(5),
        'max_score' => 1,
        'flagged_for_review' => true,
    ]);
    $unflagged = QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$this->question->id],
        'status' => 'submitted',
        'started_at' => now()->subMinutes(10),
        'expires_at' => now()->subMinutes(5),
        'submitted_at' => now()->subMinutes(5),
        'max_score' => 1,
        'flagged_for_review' => false,
    ]);

    Livewire::test(ListFlaggedQuizAttempts::class)
        ->assertCanSeeTableRecords([$flagged])
        ->assertCanNotSeeTableRecords([$unflagged]);
});
