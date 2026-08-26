<?php

use App\Domains\Analytics\Services\ReportCardDataAggregator;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
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
    $this->periodMonth = now()->startOfMonth();

    $this->course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('computes attendance, quiz average and engagement ratio from this months real data', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => $this->periodMonth->clone()->addDay(),
        'duration_minutes' => 60,
        'position' => 0,
    ]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => $this->periodMonth->clone()->addDay()]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'absent', 'source' => 'manual', 'recorded_at' => $this->periodMonth->clone()->addDays(2)]);

    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0, 'video_duration_seconds' => 100]);
    $event = VideoWatchEvent::create(['user_id' => $this->student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 50]);
    $event->forceFill(['created_at' => $this->periodMonth->clone()->addDay()])->save();

    $quizLesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Quiz', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5, 'status' => 'published']);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => $this->periodMonth->clone()->addDay(),
        'expires_at' => $this->periodMonth->clone()->addDay()->addHour(),
        'max_score' => 10,
        'score' => 8,
    ]);
    $attempt->forceFill(['created_at' => $this->periodMonth->clone()->addDay()])->save();

    $summary = app(ReportCardDataAggregator::class)->summarize($this->student, $this->periodMonth);

    expect($summary['attendance_percent'])->toBe(50.0)
        ->and($summary['quiz_average_percent'])->toBe(80.0)
        ->and($summary['engagement_ratio_percent'])->toBe(50.0);
});

it('excludes activity from outside the given calendar month', function () {
    $session = BatchSession::create([
        'batch_id' => $this->batch->id,
        'starts_at' => $this->periodMonth->clone()->subMonth(),
        'duration_minutes' => 60,
        'position' => 0,
    ]);
    AttendanceRecord::create(['attendable_type' => BatchSession::class, 'attendable_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present', 'source' => 'manual', 'recorded_at' => $this->periodMonth->clone()->subMonth()]);

    $summary = app(ReportCardDataAggregator::class)->summarize($this->student, $this->periodMonth);

    expect($summary['attendance_percent'])->toBe(0.0);
});

it('returns zeroed-out values, not null or an error, for a student with zero activity that month', function () {
    $summary = app(ReportCardDataAggregator::class)->summarize($this->student, $this->periodMonth);

    expect($summary)->toBe([
        'attendance_percent' => 0.0,
        'quiz_average_percent' => 0.0,
        'engagement_ratio_percent' => 0.0,
    ]);
});
