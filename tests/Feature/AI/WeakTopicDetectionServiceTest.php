<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function weakTopicFixture(): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);

    return [$tenant, $student, $course, $section, $lesson, $attempt];
}

function weakTopicQuestionAndAnswer(int $courseId, int $attemptId, string $concept, bool $isCorrect): QuizAnswer
{
    $question = Question::create([
        'course_id' => $courseId,
        'type' => 'mcq',
        'stem' => 'Q?',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'concept' => $concept,
        'source' => 'manual',
    ]);

    return QuizAnswer::create([
        'quiz_attempt_id' => $attemptId,
        'question_id' => $question->id,
        'is_correct' => $isCorrect,
        'status' => 'auto_graded',
    ]);
}

it('excludes a concept the student answered correctly', function () {
    [, $student, $course, , , $attempt] = weakTopicFixture();

    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Newtons Laws', true);

    $weak = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);

    expect($weak->pluck('concept')->all())->not->toContain('Newtons Laws');
});

it('reports a weak concept with the lesson(s) that cover it, matched by exact normalized name', function () {
    [, $student, $course, , $lesson, $attempt] = weakTopicFixture();

    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Kinematics', false);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [['name' => '  Kinematics  ', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    $weak = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);

    expect($weak)->toHaveCount(1)
        ->and($weak->first()['concept'])->toBe('Kinematics')
        ->and($weak->first()['correct_count'])->toBe(0)
        ->and($weak->first()['total_count'])->toBe(1)
        ->and($weak->first()['lessons']->pluck('id')->all())->toBe([$lesson->id]);
});

it('still reports a weak concept with no matching lesson_artifacts entry, instead of silently dropping it', function () {
    [, $student, $course, , , $attempt] = weakTopicFixture();

    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Unmapped Concept', false);

    $weak = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);

    expect($weak)->toHaveCount(1)
        ->and($weak->first()['concept'])->toBe('Unmapped Concept')
        ->and($weak->first()['lessons'])->toBeEmpty();
});

it('never includes another tenants or another students quiz answers', function () {
    [$tenant, $student, $course, , , $attempt] = weakTopicFixture();
    weakTopicQuestionAndAnswer($course->id, $attempt->id, 'Own Weak Concept', false);

    // A different student in the SAME tenant — must never leak into this
    // student's own weak-topics report.
    $classmate = User::factory()->create(['tenant_id' => $tenant->id]);
    $classmateAttempt = QuizAttempt::create([
        'quiz_id' => Quiz::first()->id,
        'user_id' => $classmate->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
    weakTopicQuestionAndAnswer($course->id, $classmateAttempt->id, 'Classmates Weak Concept', false);

    // A different tenant entirely.
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherCourse = Course::create(['title' => 'Chem', 'slug' => 'chem-'.uniqid()]);
    $otherSection = CourseSection::create(['course_id' => $otherCourse->id, 'title' => 'S', 'position' => 0]);
    $otherLesson = Lesson::create(['course_section_id' => $otherSection->id, 'title' => 'L', 'type' => 'video', 'position' => 0]);
    $otherQuiz = Quiz::create(['lesson_id' => $otherLesson->id, 'title' => 'Q', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);
    $otherAttempt = QuizAttempt::create([
        'quiz_id' => $otherQuiz->id,
        'user_id' => $otherStudent->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
    weakTopicQuestionAndAnswer($otherCourse->id, $otherAttempt->id, 'Cross Tenant Weak Concept', false);

    app(CurrentTenant::class)->set($tenant);

    $weak = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);

    expect($weak->pluck('concept')->all())
        ->toBe(['Own Weak Concept'])
        ->not->toContain('Classmates Weak Concept')
        ->not->toContain('Cross Tenant Weak Concept');
});
