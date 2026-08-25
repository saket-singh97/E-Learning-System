<?php

use App\Domains\AI\Services\CourseOutlineApprovalService;
use App\Domains\AI\Services\CourseOutlineGenerator;
use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S17 B13 — lifecycle E2E, same posture every prior slice's own final
 * block already established (S11 B16/.../S16 B17): proves the real chains
 * fire end to end through the actual services, not just that each block's
 * own model relationships line up in isolation.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('chains outline generation through instructor approval into real Course/Section/Lesson rows', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $draft = app(CourseOutlineGenerator::class)->generate('Thermodynamics', 'beginner', $instructor);

    expect($draft->status)->toBe('draft')
        ->and($draft->generated_outline)->not->toBeEmpty();

    $approved = app(CourseOutlineApprovalService::class)->approve($draft);

    expect($approved->status)->toBe('approved')
        ->and($approved->created_course_id)->not->toBeNull();

    $course = Course::with('sections.lessons')->findOrFail($approved->created_course_id);
    $expectedSections = collect($draft->generated_outline);

    expect($course->title)->toBe('Thermodynamics')
        ->and($course->sections)->toHaveCount($expectedSections->count());

    foreach ($expectedSections as $expectedSection) {
        $matchingSection = $course->sections->firstWhere('title', $expectedSection['section_title']);

        expect($matchingSection)->not->toBeNull()
            ->and($matchingSection->lessons->pluck('title')->all())
            ->toBe(collect($expectedSection['lessons'])->pluck('title')->all());
    }
});

it('chains real quiz grading through concept tagging into a weak-topic report that excludes the correctly answered concept', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Quiz',
        'status' => 'published',
        'blueprint' => [],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
    ]);

    $correctlyAnsweredQuestion = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is displacement?',
        'options' => ['A change in position', 'A change in speed'],
        'correct_answer' => 'A change in position',
        'difficulty' => 'easy',
        'concept' => 'Displacement',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $incorrectlyAnsweredQuestion = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is acceleration?',
        'options' => ['Rate of change of velocity', 'Distance travelled'],
        'correct_answer' => 'Rate of change of velocity',
        'difficulty' => 'easy',
        'concept' => 'Acceleration',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'question_ids' => [$correctlyAnsweredQuestion->id, $incorrectlyAnsweredQuestion->id],
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 2,
    ]);

    // Real grading, not a hand-set is_correct — QuizAttemptService::submit()
    // is the same production grading path every online attempt uses.
    app(QuizAttemptService::class)->submit($attempt, [
        $correctlyAnsweredQuestion->id => 'A change in position',
        $incorrectlyAnsweredQuestion->id => 'Distance travelled',
    ]);

    $weak = app(WeakTopicDetectionService::class)->weakTopicsFor($student, $course->id);

    expect($weak->pluck('concept')->all())
        ->not->toContain('Displacement')
        ->toContain('Acceleration');
});
