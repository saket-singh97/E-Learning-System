<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Caching\AggregateCache;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create(['subdomain' => 'physics']));

    $this->service = app(GradebookService::class);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->student = User::factory()->create(['tenant_id' => $this->course->tenant_id]);
});

function quizLessonForGradebook(): Lesson
{
    return Lesson::create(['course_section_id' => test()->section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
}

function assignmentLessonForGradebook(): Lesson
{
    return Lesson::create(['course_section_id' => test()->section->id, 'title' => 'Assignment topic', 'type' => 'assignment', 'position' => 1]);
}

it('weights quiz and assignment percent by the course own gradebook_weights', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint',
        'blueprint' => [],
        'time_limit_minutes' => 20,
        'max_attempts' => 3,
    ]);

    QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(),
        'score' => 8,
        'max_score' => 10,
    ]);

    $assignment = Assignment::create([
        'lesson_id' => assignmentLessonForGradebook()->id,
        'title' => 'Homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 50,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [],
        'submitted_at' => now(),
        'is_late' => false,
        'marks_awarded' => 30,
        'graded_by_user_id' => $this->student->id,
        'graded_at' => now(),
    ]);

    $this->course->update(['gradebook_weights' => ['quiz' => 40, 'assignment' => 60], 'passing_threshold' => 50]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    // Quiz: 8/10 = 80%. Assignment: 30/50 = 60%. Weighted: 80*0.4 + 60*0.6 = 68.
    expect($score['quiz_percent'])->toBe(80.0)
        ->and($score['assignment_percent'])->toBe(60.0)
        ->and($score['weighted_percent'])->toBe(68.0)
        ->and($score['is_passing'])->toBeTrue();
});

it('renormalizes to the remaining weight when a category has no gradeable items', function () {
    $assignment = Assignment::create([
        'lesson_id' => assignmentLessonForGradebook()->id,
        'title' => 'Homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 50,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    AssignmentSubmission::create([
        'assignment_id' => $assignment->id,
        'user_id' => $this->student->id,
        'version_number' => 1,
        'files' => [],
        'submitted_at' => now(),
        'is_late' => false,
        'marks_awarded' => 45,
        'graded_by_user_id' => $this->student->id,
        'graded_at' => now(),
    ]);

    // No quiz anywhere in this course — quiz_percent must drop out
    // entirely rather than score as 0 and drag the weighted average down.
    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['quiz_percent'])->toBeNull()
        ->and($score['assignment_percent'])->toBe(90.0)
        ->and($score['weighted_percent'])->toBe(90.0);
});

it('scores the best attempt per quiz, not every attempt averaged', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint',
        'blueprint' => [],
        'time_limit_minutes' => 20,
        'max_attempts' => 3,
    ]);

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 2, 'max_score' => 10,
    ]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['quiz_percent'])->toBe(90.0);
});

it('scores the latest graded assignment version, not the highest-scoring one', function () {
    $assignment = Assignment::create([
        'lesson_id' => assignmentLessonForGradebook()->id,
        'title' => 'Homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 100,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 95, 'graded_by_user_id' => $this->student->id, 'graded_at' => now(),
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 2,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 40, 'graded_by_user_id' => $this->student->id, 'graded_at' => now(),
    ]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['assignment_percent'])->toBe(40.0);
});

it('falls back to the latest graded version when a newer version is still ungraded', function () {
    $assignment = Assignment::create([
        'lesson_id' => assignmentLessonForGradebook()->id,
        'title' => 'Homework',
        'due_at' => now()->addWeek(),
        'max_marks' => 100,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 2000,
    ]);

    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 70, 'graded_by_user_id' => $this->student->id, 'graded_at' => now(),
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 2,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
    ]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['assignment_percent'])->toBe(70.0);
});

it('scores 0 and not-passing when the course has no gradeable items at all', function () {
    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['weighted_percent'])->toBe(0.0)
        ->and($score['is_passing'])->toBeFalse()
        // Absence of evidence is not evidence of struggling — no data
        // yet must not trip the at-risk flag just because
        // weighted_percent defaults to 0.0 (same posture
        // StudentMetrics::isAtRisk() already takes for attendance).
        ->and($score['is_at_risk'])->toBeFalse();
});

it('flags at-risk below GradebookService::GRADEBOOK_AT_RISK_THRESHOLD, not below the course own passing_threshold', function () {
    $this->course->update(['passing_threshold' => 40]);

    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    // 50% clears this course's own 40% passing_threshold but still sits
    // below GRADEBOOK_AT_RISK_THRESHOLD (60%) — a passing student can
    // still be flagged as trending at-risk.
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 5, 'max_score' => 10,
    ]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['is_passing'])->toBeTrue()
        ->and($score['is_at_risk'])->toBeTrue();
});

it('does not flag at-risk once the weighted score clears GRADEBOOK_AT_RISK_THRESHOLD', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 7, 'max_score' => 10,
    ]);

    $score = $this->service->scoreFor($this->student, $this->course->fresh());

    expect($score['is_at_risk'])->toBeFalse();
});

it('marks a course incomplete when a quiz or assignment has not been attempted yet', function () {
    Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);

    expect($this->service->isComplete($this->student, $this->course->fresh()))->toBeFalse();
});

it('marks a course complete once every quiz/assignment is attempted and the weighted score passes', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);

    $assignment = Assignment::create([
        'lesson_id' => assignmentLessonForGradebook()->id,
        'title' => 'Homework', 'due_at' => now()->addWeek(), 'max_marks' => 100,
        'allowed_file_types' => ['pdf'], 'max_file_size_kb' => 2000,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 85, 'graded_by_user_id' => $this->student->id, 'graded_at' => now(),
    ]);

    expect($this->service->isComplete($this->student, $this->course->fresh()))->toBeTrue();
});

it('marks a course incomplete when everything was attempted but the weighted score does not clear the passing threshold', function () {
    $this->course->update(['passing_threshold' => 90]);

    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 5, 'max_score' => 10,
    ]);

    expect($this->service->isComplete($this->student, $this->course->fresh()))->toBeFalse();
});

it('caches scoreFor() — a direct DB change is not reflected until the cache is explicitly forgotten (S12 B12)', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 5, 'max_score' => 10,
    ]);

    $first = $this->service->scoreFor($this->student, $this->course->fresh());
    expect($first['quiz_percent'])->toBe(50.0);

    // Bypasses the service entirely, so no bust fires — the cache should
    // still hand back the stale 50.0 on this next call.
    $attempt->update(['score' => 10]);
    $stale = $this->service->scoreFor($this->student, $this->course->fresh());
    expect($stale['quiz_percent'])->toBe(50.0);

    app(AggregateCache::class)->forgetGradebookScore($this->student->tenant_id, $this->student->id, $this->course->id);

    $fresh = $this->service->scoreFor($this->student, $this->course->fresh());
    expect($fresh['quiz_percent'])->toBe(100.0);
});

it('keys the cache per student and per course, not shared across either', function () {
    $quiz = Quiz::create([
        'lesson_id' => quizLessonForGradebook()->id,
        'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3,
    ]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 3, 'max_score' => 10,
    ]);
    $otherStudent = User::factory()->create(['tenant_id' => $this->course->tenant_id]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $otherStudent->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);

    $mine = $this->service->scoreFor($this->student, $this->course->fresh());
    $theirs = $this->service->scoreFor($otherStudent, $this->course->fresh());

    expect($mine['quiz_percent'])->toBe(30.0)
        ->and($theirs['quiz_percent'])->toBe(90.0);
});
