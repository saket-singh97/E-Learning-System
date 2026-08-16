<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Jobs\CheckCourseCompletionAndIssueCertificates;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Assignments\Models\AssignmentSubmission;
use App\Domains\Assignments\Models\Certificate;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
});

/**
 * @return array{tenant: Tenant, course: Course, student: User, quiz: Quiz, assignment: Assignment, batch: Batch}
 */
function certificateSweepFixture(?Tenant $tenant = null): array
{
    $tenant ??= Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $assignmentLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Homework', 'type' => 'assignment', 'position' => 1]);

    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3]);
    $assignment = Assignment::create([
        'lesson_id' => $assignmentLesson->id, 'title' => 'Homework', 'due_at' => now()->addWeek(),
        'max_marks' => 100, 'allowed_file_types' => ['pdf'], 'max_file_size_kb' => 2000,
    ]);

    $batch = Batch::create([
        'course_id' => $course->id, 'title' => 'Batch', 'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only', 'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    return compact('tenant', 'course', 'student', 'quiz', 'assignment', 'batch');
}

it('issues a certificate once every quiz and assignment is attempted and the weighted score passes', function () {
    ['course' => $course, 'student' => $student, 'quiz' => $quiz, 'assignment' => $assignment] = certificateSweepFixture();

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 85, 'graded_by_user_id' => $student->id, 'graded_at' => now(),
    ]);

    CheckCourseCompletionAndIssueCertificates::dispatch();

    $certificate = Certificate::sole();

    expect($certificate->user_id)->toBe($student->id)
        ->and($certificate->course_id)->toBe($course->id)
        ->and(Storage::disk('local')->exists($certificate->pdf_disk_path))->toBeTrue();
});

it('does not issue a certificate when a quiz or assignment has not been attempted yet', function () {
    certificateSweepFixture();

    CheckCourseCompletionAndIssueCertificates::dispatch();

    expect(Certificate::count())->toBe(0);
});

it('does not issue a certificate when everything was attempted but the weighted score does not clear the passing threshold', function () {
    ['course' => $course, 'student' => $student, 'quiz' => $quiz, 'assignment' => $assignment] = certificateSweepFixture();
    $course->update(['passing_threshold' => 90]);

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 3, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 30, 'graded_by_user_id' => $student->id, 'graded_at' => now(),
    ]);

    CheckCourseCompletionAndIssueCertificates::dispatch();

    expect(Certificate::count())->toBe(0);
});

it('does not issue a duplicate certificate on a second sweep run', function () {
    ['student' => $student, 'quiz' => $quiz, 'assignment' => $assignment] = certificateSweepFixture();

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 90, 'graded_by_user_id' => $student->id, 'graded_at' => now(),
    ]);

    CheckCourseCompletionAndIssueCertificates::dispatch();
    CheckCourseCompletionAndIssueCertificates::dispatch();

    expect(Certificate::count())->toBe(1);
});

it('sweeps every tenant in one unscoped run', function () {
    // Each tenant's fixture AND its quiz/assignment data is built while
    // that same tenant is bound as CurrentTenant — QuizAttempt/
    // AssignmentSubmission are BelongsToTenant too, so creating tenant
    // B's rows while tenant A is still bound would silently mis-tag them.
    ['student' => $studentA, 'quiz' => $quizA, 'assignment' => $assignmentA] = certificateSweepFixture();
    QuizAttempt::create([
        'quiz_id' => $quizA->id, 'user_id' => $studentA->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignmentA->id, 'user_id' => $studentA->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 90, 'graded_by_user_id' => $studentA->id, 'graded_at' => now(),
    ]);

    $tenantB = Tenant::factory()->create();
    ['student' => $studentB, 'quiz' => $quizB, 'assignment' => $assignmentB] = certificateSweepFixture($tenantB);
    QuizAttempt::create([
        'quiz_id' => $quizB->id, 'user_id' => $studentB->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignmentB->id, 'user_id' => $studentB->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 90, 'graded_by_user_id' => $studentB->id, 'graded_at' => now(),
    ]);

    CheckCourseCompletionAndIssueCertificates::dispatch();

    expect(Certificate::count())->toBe(2)
        ->and(Certificate::where('user_id', $studentA->id)->exists())->toBeTrue()
        ->and(Certificate::where('user_id', $studentB->id)->exists())->toBeTrue();
});

it('has retry configured, matching every other unscoped sweep job in this app', function () {
    $job = new CheckCourseCompletionAndIssueCertificates;

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('skips an enrolment whose batch was soft-deleted instead of crashing the whole sweep', function () {
    // S10 B15 review pass: Batch has no delete guard against active
    // enrolments (unlike Assignment/Quiz, which both hide delete once
    // attempts exist) — an active Enrolment can point at a soft-deleted
    // batch, and this sweep used to dereference ->batch->course
    // unconditionally.
    ['student' => $student, 'quiz' => $quiz, 'assignment' => $assignment, 'batch' => $batch] = certificateSweepFixture();

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 9, 'max_score' => 10,
    ]);
    AssignmentSubmission::create([
        'assignment_id' => $assignment->id, 'user_id' => $student->id, 'version_number' => 1,
        'files' => [], 'submitted_at' => now(), 'is_late' => false,
        'marks_awarded' => 90, 'graded_by_user_id' => $student->id, 'graded_at' => now(),
    ]);

    $batch->delete();

    CheckCourseCompletionAndIssueCertificates::dispatch();

    expect(Certificate::count())->toBe(0);
});
