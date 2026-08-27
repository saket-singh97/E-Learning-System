<?php

use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Assignments\Models\Assignment;
use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\QuizAttemptAlreadySubmittedException;
use App\Livewire\AssignmentSubmit;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('awards xp when AttendanceRecorded fires present but not absent', function () {
    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'present'));

    expect(GamificationProfile::where('user_id', $this->student->id)->sole()->xp_total)
        ->toBe(GamificationService::XP_ATTENDANCE);

    event(new AttendanceRecorded($this->student->id, $this->tenant->id, 'absent'));

    expect(GamificationProfile::where('user_id', $this->student->id)->sole()->xp_total)
        ->toBe(GamificationService::XP_ATTENDANCE);
});

it('never awards xp when AttendanceRecorded fires for a non-student (e.g. an instructor marking their own attendance)', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    event(new AttendanceRecorded($instructor->id, $this->tenant->id, 'present'));

    expect(GamificationProfile::where('user_id', $instructor->id)->exists())->toBeFalse();
});

it('awards xp exactly once for a quiz attempt submit, never on a rejected resubmit', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Test quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
    ]);
    $question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'Q?',
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$question->id],
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'max_score' => 1,
    ]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => 'A']);

    expect(GamificationProfile::where('user_id', $this->student->id)->sole()->xp_total)
        ->toBe(GamificationService::XP_QUIZ_SUBMISSION);

    // A retried submit on the same attempt is rejected before the event
    // fires (QuizAttemptService's own guard), so XP must not double-award.
    expect(fn () => app(QuizAttemptService::class)->submit($attempt->fresh(), [$question->id => 'A']))
        ->toThrow(QuizAttemptAlreadySubmittedException::class);

    expect(GamificationProfile::where('user_id', $this->student->id)->sole()->xp_total)
        ->toBe(GamificationService::XP_QUIZ_SUBMISSION);
});

it('awards xp for an assignment submission', function () {
    Storage::fake('local');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Homework', 'type' => 'assignment', 'position' => 0]);
    $assignment = Assignment::create([
        'lesson_id' => $lesson->id,
        'title' => 'Problem Set',
        'due_at' => now()->addWeek(),
        'max_marks' => 50,
        'allowed_file_types' => ['pdf'],
        'max_file_size_kb' => 5000,
    ]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonths(3)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    // Through the Livewire component, not the service directly — Livewire's
    // own upload handling is what turns a client file into the
    // TemporaryUploadedFile storeVersion() type-hints; a plain
    // UploadedFile::fake() passed straight to the service fails that hint.
    Livewire::actingAs($this->student)
        ->test(AssignmentSubmit::class, ['assignment' => $assignment])
        ->set('files', [UploadedFile::fake()->create('answer.pdf', 500, 'application/pdf')])
        ->call('submit')
        ->assertHasNoErrors();

    expect(GamificationProfile::where('user_id', $this->student->id)->sole()->xp_total)
        ->toBe(GamificationService::XP_ASSIGNMENT_SUBMISSION);
});
