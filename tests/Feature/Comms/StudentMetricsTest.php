<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Services\StudentMetrics;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->metrics = new StudentMetrics;
});

function batchSessionRow(): BatchSession
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live', 'type' => 'live_session', 'position' => 0]);

    return BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => now()->addWeek(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://meet.example.com/row',
    ]);
}

function videoLesson(?int $durationSeconds = 100): Lesson
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);

    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Lecture',
        'type' => 'video',
        'position' => 0,
        'video_duration_seconds' => $durationSeconds,
    ]);
}

function quizRow(): Quiz
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);

    return Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Quiz',
        'blueprint' => [['difficulty' => 'easy', 'count' => 1]],
        'time_limit_minutes' => 30,
        'max_attempts' => 3,
    ]);
}

function feeInvoiceRow(User $student, string $status, string $dueDate): FeeInvoice
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
        'is_paid' => true,
        'price' => 100,
    ]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    return FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => $dueDate,
        'status' => $status,
    ]);
}

it('computes attendance percent from BatchSession rows only, ignoring video-coverage Lesson rows', function () {
    $session = batchSessionRow();

    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id + 1, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => now()]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id + 2, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => now()]);

    // A video-coverage row for an unrelated Lesson id — must not affect the ratio at all.
    $lesson = videoLesson();
    AttendanceRecord::create(['attendable_type' => Lesson::class, 'attendable_id' => $lesson->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'video_coverage', 'recorded_at' => now()]);

    expect($this->metrics->summarize($this->student)['attendance_percent'])->toBe(66.7);
});

it('returns a null attendance percent when no BatchSession rows exist yet', function () {
    $lesson = videoLesson();
    AttendanceRecord::create(['attendable_type' => Lesson::class, 'attendable_id' => $lesson->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'video_coverage', 'recorded_at' => now()]);

    expect($this->metrics->summarize($this->student)['attendance_percent'])->toBeNull();
});

it('computes coverage percent from merged watch intervals for a single lesson', function () {
    $lesson = videoLesson(100);

    VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 50]);
    VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $lesson->id, 'start_s' => 40, 'end_s' => 80]);

    expect($this->metrics->summarize($this->student)['coverage_percent'])->toBe(80.0);
});

it('averages coverage percent across multiple lessons', function () {
    $fullyWatched = videoLesson(100);
    VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $fullyWatched->id, 'start_s' => 0, 'end_s' => 100]);

    $halfWatched = videoLesson(100);
    VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $halfWatched->id, 'start_s' => 0, 'end_s' => 50]);

    expect($this->metrics->summarize($this->student)['coverage_percent'])->toBe(75.0);
});

it('returns a null coverage percent when no video watch events exist yet', function () {
    expect($this->metrics->summarize($this->student)['coverage_percent'])->toBeNull();
});

it('averages the score/max_score ratio per completed quiz attempt, excluding in-progress and ungraded ones', function () {
    $quiz = quizRow();

    QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [1], 'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'score' => 8, 'max_score' => 10]);
    QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [1], 'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'score' => 5, 'max_score' => 10]);
    // Still in progress — excluded.
    QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [1], 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'max_score' => 10]);
    // Submitted but not yet graded (pending short-answer review) — excluded.
    QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [1], 'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'max_score' => 10]);

    expect($this->metrics->summarize($this->student)['quiz_average_percent'])->toBe(65.0);
});

it('returns a null quiz average when the student has no completed attempts', function () {
    expect($this->metrics->summarize($this->student)['quiz_average_percent'])->toBeNull();
});

it('reports overdue fee status even when other invoices are paid', function () {
    feeInvoiceRow($this->student, 'paid', now()->subMonth()->toDateString());
    feeInvoiceRow($this->student, 'overdue', now()->subDays(3)->toDateString());

    expect($this->metrics->summarize($this->student)['fee_status'])->toBe('overdue');
});

it('reports pending fee status when nothing is overdue', function () {
    feeInvoiceRow($this->student, 'paid', now()->subMonth()->toDateString());
    feeInvoiceRow($this->student, 'pending', now()->addWeek()->toDateString());

    expect($this->metrics->summarize($this->student)['fee_status'])->toBe('pending');
});

it('reports up_to_date fee status when every invoice is paid', function () {
    feeInvoiceRow($this->student, 'paid', now()->subMonth()->toDateString());

    expect($this->metrics->summarize($this->student)['fee_status'])->toBe('up_to_date');
});

it('lists only pending/overdue invoices as outstanding, ordered by due date', function () {
    feeInvoiceRow($this->student, 'paid', now()->subMonth()->toDateString());
    $later = feeInvoiceRow($this->student, 'pending', now()->addWeek()->toDateString());
    $sooner = feeInvoiceRow($this->student, 'overdue', now()->subDays(3)->toDateString());

    $outstanding = $this->metrics->summarize($this->student)['outstanding_invoices'];

    expect($outstanding->pluck('id')->all())->toBe([$sooner->id, $later->id]);
});
