<?php

use App\Domains\Analytics\Services\PeerComparisonService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function peerComparisonAttempt(int $quizId, int $studentId): QuizAttempt
{
    return QuizAttempt::create([
        'quiz_id' => $quizId,
        'user_id' => $studentId,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
}

function peerComparisonAnswer(int $courseId, int $attemptId, string $concept, bool $isCorrect): QuizAnswer
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

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $this->quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);

    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);
});

function peerComparisonEnrol(Batch $batch, User $student): void
{
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
}

it('matches a hand-computed percentile among real seeded classmates\' concept scores', function () {
    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $studentC = User::factory()->create(['tenant_id' => $this->tenant->id]);

    foreach ([$studentA, $studentB, $studentC] as $student) {
        peerComparisonEnrol($this->batch, $student);
    }

    // A: 2/2 correct (ratio 1.0), B: 1/2 (ratio 0.5), C: 0/2 (ratio 0.0).
    $attemptA = peerComparisonAttempt($this->quiz->id, $studentA->id);
    peerComparisonAnswer($this->course->id, $attemptA->id, 'Kinematics', true);
    peerComparisonAnswer($this->course->id, $attemptA->id, 'Kinematics', true);

    $attemptB = peerComparisonAttempt($this->quiz->id, $studentB->id);
    peerComparisonAnswer($this->course->id, $attemptB->id, 'Kinematics', true);
    peerComparisonAnswer($this->course->id, $attemptB->id, 'Kinematics', false);

    $attemptC = peerComparisonAttempt($this->quiz->id, $studentC->id);
    peerComparisonAnswer($this->course->id, $attemptC->id, 'Kinematics', false);
    peerComparisonAnswer($this->course->id, $attemptC->id, 'Kinematics', false);

    $service = app(PeerComparisonService::class);

    // Beat 2/3, 1/3, 0/3 respectively — same "strictly lower" formula
    // TestSeriesRankingServiceTest already hand-verifies.
    expect($service->percentileFor($studentA, $this->batch, 'Kinematics'))->toBe(round(2 / 3 * 100, 1))
        ->and($service->percentileFor($studentB, $this->batch, 'Kinematics'))->toBe(round(1 / 3 * 100, 1))
        ->and($service->percentileFor($studentC, $this->batch, 'kinematics'))->toBe(0.0);
});

it('returns null, not an error, when only one student has data for the concept', function () {
    $lonelyStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    peerComparisonEnrol($this->batch, $lonelyStudent);

    $attempt = peerComparisonAttempt($this->quiz->id, $lonelyStudent->id);
    peerComparisonAnswer($this->course->id, $attempt->id, 'Kinematics', true);

    expect(app(PeerComparisonService::class)->percentileFor($lonelyStudent, $this->batch, 'Kinematics'))->toBeNull();
});

it('returns null for a concept the student never answered', function () {
    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    peerComparisonEnrol($this->batch, $studentA);
    peerComparisonEnrol($this->batch, $studentB);

    $attemptB = peerComparisonAttempt($this->quiz->id, $studentB->id);
    peerComparisonAnswer($this->course->id, $attemptB->id, 'Kinematics', true);

    expect(app(PeerComparisonService::class)->percentileFor($studentA, $this->batch, 'Kinematics'))->toBeNull();
});
