<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\LessonCompletion;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->completion = app(LessonCompletion::class);
});

it('marks a video lesson complete only once watched past the configured threshold', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Video', 'type' => 'video', 'position' => 0, 'video_duration_seconds' => 100]);

    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 50]);
    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 91]);
    expect($this->completion->isComplete($lesson, $this->student))->toBeTrue();
});

it('marks a quiz lesson complete once any non-in_progress attempt exists', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'blueprint' => [], 'time_limit_minutes' => 30, 'max_attempts' => 3]);

    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->student->id, 'question_ids' => [], 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'max_score' => 10]);
    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    $attempt->update(['status' => 'submitted']);
    expect($this->completion->isComplete($lesson, $this->student))->toBeTrue();
});

it('marks an assignment lesson complete once any submission exists', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Assignment', 'type' => 'assignment', 'position' => 0]);
    $assignment = Assignment::create(['lesson_id' => $lesson->id, 'title' => 'Assignment', 'due_at' => now()->addWeek(), 'max_marks' => 100]);

    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    AssignmentSubmission::create(['assignment_id' => $assignment->id, 'user_id' => $this->student->id, 'version_number' => 1, 'files' => [], 'submitted_at' => now()]);
    expect($this->completion->isComplete($lesson, $this->student))->toBeTrue();
});

it('marks a text lesson complete once any engagement record exists', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Reading', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);

    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();

    AttendanceRecord::create([
        'attendable_type' => Lesson::class,
        'attendable_id' => $lesson->id,
        'user_id' => $this->student->id,
        'status' => 'present',
        'source' => 'manual',
        'recorded_at' => now(),
    ]);
    expect($this->completion->isComplete($lesson, $this->student))->toBeTrue();
});

it('does not credit one student completion to another', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Video', 'type' => 'video', 'position' => 0, 'video_duration_seconds' => 100]);
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);

    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $otherStudent->id, 'start_s' => 0, 'end_s' => 99]);

    expect($this->completion->isComplete($lesson, $this->student))->toBeFalse();
});
