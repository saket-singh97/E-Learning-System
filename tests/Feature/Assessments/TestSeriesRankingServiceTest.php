<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\TestSeriesRankingService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->service = app(TestSeriesRankingService::class);

    $this->makeQuiz = function () {
        $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
        $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

        return Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Quiz '.uniqid(),
            'blueprint' => [['count' => 1]],
            'time_limit_minutes' => 20,
            'max_attempts' => 5,
            'status' => 'published',
        ]);
    };

    $this->makeAttempt = fn (Quiz $quiz, User $user, float $score): QuizAttempt => QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $user->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => $score,
        'max_score' => 10,
    ]);
});

it('ranks two students across two quizzes in one series by their summed best-per-quiz score', function () {
    $series = TestSeries::create(['title' => 'Weekly Mock']);
    $quizA = ($this->makeQuiz)();
    $quizB = ($this->makeQuiz)();
    $quizA->update(['test_series_id' => $series->id]);
    $quizB->update(['test_series_id' => $series->id]);

    $topper = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ($this->makeAttempt)($quizA, $topper, 8);
    ($this->makeAttempt)($quizB, $topper, 6);

    $runnerUp = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ($this->makeAttempt)($quizA, $runnerUp, 5);
    ($this->makeAttempt)($quizB, $runnerUp, 5);

    $rankings = $this->service->rankingsFor($series, $topper);

    $topperRow = $rankings->firstWhere('user_id', $topper->id);
    $runnerUpRow = $rankings->firstWhere('user_id', $runnerUp->id);

    expect($topperRow['total_score'])->toBe(14.0)
        ->and($topperRow['rank'])->toBe(1)
        ->and($topperRow['percentile'])->toBe(50.0);

    expect($runnerUpRow['total_score'])->toBe(10.0)
        ->and($runnerUpRow['rank'])->toBe(2)
        ->and($runnerUpRow['percentile'])->toBe(0.0);
});

it('scores a student who attempted only one of two series quizzes fairly, no zero-fill for the missing one', function () {
    $series = TestSeries::create(['title' => 'Weekly Mock']);
    $quizA = ($this->makeQuiz)();
    $quizB = ($this->makeQuiz)();
    $quizA->update(['test_series_id' => $series->id]);
    $quizB->update(['test_series_id' => $series->id]);

    $partial = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ($this->makeAttempt)($quizA, $partial, 7);
    // Never attempted quiz B.

    $rankings = $this->service->rankingsFor($series, $partial);
    $row = $rankings->firstWhere('user_id', $partial->id);

    expect($row['total_score'])->toBe(7.0);
});

it('takes the best attempt per quiz, not the sum of every attempt on it', function () {
    $series = TestSeries::create(['title' => 'Weekly Mock']);
    $quizA = ($this->makeQuiz)();
    $quizA->update(['test_series_id' => $series->id]);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ($this->makeAttempt)($quizA, $student, 4);
    ($this->makeAttempt)($quizA, $student, 9);

    $rankings = $this->service->rankingsFor($series, $student);

    expect($rankings->firstWhere('user_id', $student->id)['total_score'])->toBe(9.0);
});

it('never mixes a same-named series from a different tenant into the ranking', function () {
    $seriesA = TestSeries::create(['title' => 'National Mock']);
    $quizA = ($this->makeQuiz)();
    $quizA->update(['test_series_id' => $seriesA->id]);
    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    ($this->makeAttempt)($quizA, $viewer, 5);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $seriesB = TestSeries::create(['title' => 'National Mock']);
    $quizB = ($this->makeQuiz)();
    $quizB->update(['test_series_id' => $seriesB->id]);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    ($this->makeAttempt)($quizB, $otherStudent, 100);
    app(CurrentTenant::class)->set($this->tenant);

    $rankings = $this->service->rankingsFor($seriesA, $viewer);

    expect($rankings)->toHaveCount(1);
    expect($rankings->firstWhere('user_id', $otherStudent->id))->toBeNull();
});

it('ignores a same-id quiz from another tenant even if it were misconfigured to point at this series', function () {
    $series = TestSeries::create(['title' => 'National Mock']);
    $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $foreignQuiz = ($this->makeQuiz)();
    // Directly force a cross-tenant FK the real Admin UI would never
    // produce, to prove the service's own tenant filter (not just the
    // Admin form's own good behavior) is what keeps this out.
    $foreignQuiz->update(['test_series_id' => $series->id]);
    $foreignStudent = User::factory()->create(['tenant_id' => $otherTenant->id]);
    ($this->makeAttempt)($foreignQuiz, $foreignStudent, 100);
    app(CurrentTenant::class)->set($this->tenant);

    $rankings = $this->service->rankingsFor($series, $viewer);

    expect($rankings)->toHaveCount(0);
});
