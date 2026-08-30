<?php

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\Analytics\Services\ConfusionSignalService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->noisyLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Noisy', 'type' => 'video', 'position' => 0]);
    $this->quietLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiet', 'type' => 'video', 'position' => 1]);

    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $this->noisyLesson->id, 'starts_at' => now()->subDays(2), 'duration_minutes' => 60, 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $this->quietLesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 1]);

    // Noisy lesson gets all three signals: doubts, a rewatch, and a failed quiz answer.
    DoubtChatMessage::create(['lesson_id' => $this->noisyLesson->id, 'user_id' => $this->student->id, 'question' => 'Why?', 'answer' => 'Because.', 'cited_chunk_idxs' => [0]]);
    VideoWatchEvent::create(['lesson_id' => $this->noisyLesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    $quiz = Quiz::create(['lesson_id' => $this->noisyLesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
    $question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'Q?',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'source' => 'manual',
    ]);
    QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id, 'is_correct' => false, 'status' => 'auto_graded']);
});

it('ranks a lesson with zero signals last, not excluded or erroring', function () {
    $ranked = app(ConfusionSignalService::class)->rankedFor($this->batch);

    expect($ranked)->toHaveCount(2);

    $last = $ranked->last();
    expect($last['lesson']->id)->toBe($this->quietLesson->id)
        ->and($last['doubt_count'])->toBe(0)
        ->and($last['hottest_rewatch'])->toBeNull()
        ->and($last['failure_rate'])->toBe(0.0)
        ->and($last['score'])->toBe(0.0);

    $first = $ranked->first();
    expect($first['lesson']->id)->toBe($this->noisyLesson->id)
        ->and($first['score'])->toBeGreaterThan(0.0);
});

it('produces the same order across repeated calls with unchanged data', function () {
    $service = app(ConfusionSignalService::class);

    $firstRun = $service->rankedFor($this->batch)->pluck('lesson.id')->all();
    $secondRun = $service->rankedFor($this->batch)->pluck('lesson.id')->all();

    expect($firstRun)->toBe($secondRun);
});
