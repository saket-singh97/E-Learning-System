<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\RankCardGenerator;
use App\Domains\Assessments\Services\TestSeriesRankingService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\SyllabusTopic;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\SyllabusTracker;
use App\Domains\Assessments\Services\HallTicketGenerator;
use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Services\ScholarshipConversionService;
use App\Domains\Growth\Services\ScholarshipTestGradingService;
use App\Domains\Growth\Services\ScholarshipTestRegistrationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * S23 B21 — the one thing no per-block test could cover: real stories
 * chained end to end through the actual services, same posture every
 * prior slice's own final block established (e.g. S20LifecycleE2ETest).
 *
 * Design doc §5 names one chain as "scholarship test registration → score
 * → rank card + hall ticket generated → conversion tracked" — but rank
 * cards (U15, design §4 decision 1/8) are generated per `TestSeries` for
 * an *enrolled, authenticated* `User` via `TestSeriesRankingService`,
 * while a scholarship-test registration (U12, design §4 decision 2) is
 * deliberately for a prospect with no `User` row at all — there is no
 * schema link between `ScholarshipTest` and `TestSeries`, so a single
 * literal pipeline through both isn't something the actual build supports.
 * Split into two real, independently-chained stories below instead: the
 * scholarship test's own registration → grade → hall ticket → conversion
 * lifecycle, and a separate rank-card generation lifecycle for a real
 * `TestSeries` participant — each end to end through its own real
 * services, matching every other roadmap item's own treatment here.
 */
uses(RefreshDatabase::class);

it('registers for a scholarship test, grades it, issues a hall ticket, then tracks conversion once a real enrolment lands', function () {
    Storage::fake('local');
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);

    $question = Question::create([
        'course_id' => $course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4', '5'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Scholarship Quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 60,
        'max_attempts' => 1,
    ]);
    $scholarshipTest = ScholarshipTest::create([
        'course_id' => $course->id,
        'title' => 'National Scholarship Test',
        'quiz_id' => $quiz->id,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addWeek(),
    ]);

    // Registration.
    $registration = app(ScholarshipTestRegistrationService::class)->register(
        $scholarshipTest, 'Asha Prospect', 'asha@example.com', null,
    );
    expect($registration->score)->toBeNull();

    // Grade — the same question-drawing the registration page itself uses.
    $questionIds = app(ScholarshipTestGradingService::class)->drawQuestions($scholarshipTest);
    expect($questionIds)->toBe([$question->id]);

    $graded = app(ScholarshipTestGradingService::class)->grade($registration, [$question->id => '4']);
    expect((float) $graded->score)->toBe(1.0);

    // Hall ticket.
    $hallTicket = app(HallTicketGenerator::class)->generate($registration);
    expect($hallTicket->roll_number)->toBe("{$scholarshipTest->id}-0001");
    expect(Storage::disk('local')->exists($hallTicket->pdf_path))->toBeTrue();

    // Not converted yet.
    $conversionService = app(ScholarshipConversionService::class);
    expect($conversionService->hasConverted($registration))->toBeFalse();

    // A real enrolment lands under the same email, weeks later.
    $enrolledUser = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'asha@example.com']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonths(3)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $enrolledUser->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    expect($conversionService->hasConverted($registration))->toBeTrue();
});

it('generates a rank card for a real test-series participant, matching the ranking service\'s own numbers', function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz Lesson', 'type' => 'quiz', 'position' => 0]);
    $series = TestSeries::create(['title' => 'Weekly Mock Series']);
    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Mock 1',
        'blueprint' => [],
        'time_limit_minutes' => 30,
        'max_attempts' => 3,
        'test_series_id' => $series->id,
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'question_ids' => [1],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
        'submitted_at' => now(),
        'score' => 8,
        'max_score' => 10,
    ]);

    $rankCard = app(RankCardGenerator::class)->generate($series, $student);
    $ranking = app(TestSeriesRankingService::class)->rankingsFor($series, $student)->firstWhere('user_id', $student->id);

    expect((float) $ranking['total_score'])->toBe(8.0)
        ->and($ranking['rank'])->toBe(1)
        ->and(Storage::disk('local')->exists($rankCard->pdf_path))->toBeTrue();
});

it('raises a syllabus tracker\'s percent complete as the mapped lessons are actually completed', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Biology', 'slug' => 'biology-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lessonA = Lesson::create(['course_section_id' => $section->id, 'title' => 'Cells', 'type' => 'text', 'position' => 0, 'text_body' => 'Cells...']);
    $lessonB = Lesson::create(['course_section_id' => $section->id, 'title' => 'Genetics', 'type' => 'text', 'position' => 1, 'text_body' => 'Genetics...']);

    $topic = SyllabusTopic::create(['course_id' => $course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Life Processes', 'sequence' => 0]);
    $topic->lessons()->attach([$lessonA->id, $lessonB->id]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $tracker = app(SyllabusTracker::class);

    expect($tracker->percentCompleteFor($course, $student)['CBSE Class 10'])->toBe(0.0);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lessonA->id, 'start_s' => 0, 'end_s' => 10]);
    expect($tracker->percentCompleteFor($course, $student)['CBSE Class 10'])->toBe(50.0);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lessonB->id, 'start_s' => 0, 'end_s' => 10]);
    expect($tracker->percentCompleteFor($course, $student)['CBSE Class 10'])->toBe(100.0);
});

it('blocks a low-attendance students stream only on the batch that actually opted into the attendance gate', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $tenant->update(['attendance_linked_access_enabled' => true]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $gatedBatch = Batch::create([
        'course_id' => $course->id, 'title' => 'Gated Batch', 'slug' => 'gated-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(),
        'self_enrol_mode' => 'manual_only', 'status' => 'published', 'min_attendance_percent' => 75,
    ]);
    $ungatedBatch = Batch::create([
        'course_id' => $course->id, 'title' => 'Ungated Batch', 'slug' => 'ungated-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(),
        'self_enrol_mode' => 'manual_only', 'status' => 'published',
    ]);

    $gatedEnrolment = Enrolment::create(['batch_id' => $gatedBatch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    $ungatedEnrolment = Enrolment::create(['batch_id' => $ungatedBatch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    foreach (['gatedBatch' => $gatedBatch, 'ungatedBatch' => $ungatedBatch] as $batch) {
        foreach (['absent', 'absent', 'absent', 'absent', 'present'] as $status) {
            $session = BatchSession::create(['batch_id' => $batch->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);
            AttendanceRecord::create([
                'attendable_type' => BatchSession::class, 'attendable_id' => $session->id,
                'user_id' => $student->id, 'role' => 'student', 'status' => $status,
                'source' => 'manual', 'recorded_at' => now(),
            ]);
        }
    }

    $access = app(LessonAccess::class);

    // Both enrolments give access to the same course's lesson — this
    // student is 20% attendance in both batches, but only the gated one
    // actually enforces a threshold, so the ungated enrolment alone still
    // keeps the lesson streamable overall.
    expect($access->canStream($lesson, $student))->toBeTrue();

    // Drop the ungated enrolment — only the gated, low-attendance one is
    // left, and it now blocks.
    $ungatedEnrolment->update(['status' => 'withdrawn']);
    expect($access->canStream($lesson, $student))->toBeFalse();

    $gatedEnrolment->update(['status' => 'withdrawn']);
    $ungatedEnrolment->update(['status' => 'active']);
    expect($access->canStream($lesson, $student))->toBeTrue();
});
