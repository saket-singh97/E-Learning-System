<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\QuizProctoringEvent;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\QuizRunner;
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

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);

    Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->makeQuiz = fn (bool $proctoringEnabled): Quiz => Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 1,
        'status' => 'published',
        'proctoring_enabled' => $proctoringEnabled,
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('logs each event type once triggered on a proctoring-enabled quiz', function () {
    $quiz = ($this->makeQuiz)(true);

    $component = Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt');

    foreach (QuizProctoringEvent::EVENT_TYPES as $eventType) {
        $component->call('logProctoringEvent', $eventType);
    }

    $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $this->student->id)->sole();

    expect(QuizProctoringEvent::where('quiz_attempt_id', $attempt->id)->pluck('event_type')->all())
        ->toEqualCanonicalizing(QuizProctoringEvent::EVENT_TYPES);
});

it('logs nothing for a quiz with proctoring disabled', function () {
    $quiz = ($this->makeQuiz)(false);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->call('logProctoringEvent', 'tab_switch');

    expect(QuizProctoringEvent::count())->toBe(0);
});

it('logs nothing for an unrecognised event type', function () {
    $quiz = ($this->makeQuiz)(true);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->call('logProctoringEvent', 'window_resize');

    expect(QuizProctoringEvent::count())->toBe(0);
});

it('logs nothing when no attempt is in progress', function () {
    $quiz = ($this->makeQuiz)(true);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('logProctoringEvent', 'tab_switch');

    expect(QuizProctoringEvent::count())->toBe(0);
});

it('scopes a logged event to the current attempt and tenant', function () {
    $quiz = ($this->makeQuiz)(true);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->call('logProctoringEvent', 'copy');

    $attempt = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $this->student->id)->sole();
    $event = QuizProctoringEvent::sole();

    expect($event->quiz_attempt_id)->toBe($attempt->id);
    expect($event->tenant_id)->toBe($this->tenant->id);
    expect($event->event_type)->toBe('copy');
});
