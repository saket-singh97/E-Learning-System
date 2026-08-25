<?php

use App\Domains\Assessments\Filament\Resources\FlaggedQuizAttempts\Pages\ListFlaggedQuizAttempts;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\TestSeries;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Assessments\Services\TestSeriesRankingService;
use App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\Pages\ListTeacherAttendanceFlags;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S16 B17 — lifecycle E2E, same posture S11 B16/S12 B14/S13 B17/S14 B18/
 * S15 B21 each already established: proves the real chains fire end to
 * end through the actual jobs/services/Filament resources, not just that
 * each block's own model relationships line up in isolation.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('runs a teacher session unmarked past its end time through flag to admin reassignment', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');
    $substitute = User::factory()->create(['tenant_id' => $tenant->id]);
    $substitute->assignRole('instructor');

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $instructor->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'starts_at' => now()->subHours(2),
        'duration_minutes' => 60,
        'position' => 0,
    ]);

    // Sweep detects the unmarked session and raises a flag.
    app(CurrentTenant::class)->clear();
    app(\App\Domains\Batches\Jobs\TeacherAttendanceSweep::class)->handle();
    app(CurrentTenant::class)->set($tenant);

    $flag = \App\Domains\Batches\Models\TeacherAttendanceFlag::where('batch_session_id', $session->id)->sole();
    expect($flag->status)->toBe('open');

    // Admin reassigns the batch to a substitute while resolving the flag.
    $this->actingAs($admin)
        ->get(\App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\TeacherAttendanceFlagResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(ListTeacherAttendanceFlags::class)
        ->callTableAction('resolve', $flag, data: ['reassign_instructor_id' => $substitute->id]);

    expect($flag->fresh()->status)->toBe('resolved');
    expect($batch->fresh()->instructor_id)->toBe($substitute->id);
});

it('runs a full test-series lifecycle — two quizzes, two students, rank and percentile computed correctly', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lessonA = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz A topic', 'type' => 'quiz', 'position' => 0]);
    $lessonB = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz B topic', 'type' => 'quiz', 'position' => 1]);

    // Admin bundles two quizzes into one series through the real Admin-panel form.
    $this->actingAs($admin)
        ->get(\App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource::getUrl('create', ['subdomain' => 'chemistry'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(\App\Domains\Assessments\Filament\Resources\TestSeries\Pages\CreateTestSeries::class)
        ->fillForm(['title' => 'National Mock'])
        ->call('create')
        ->assertHasNoFormErrors();
    $series = TestSeries::sole();

    // Each quiz's blueprint filters by a distinct concept tag so start()'s
    // own draw() deterministically pulls the right question per quiz —
    // both questions otherwise sit in the same course's shared pool.
    $quizA = Quiz::create(['lesson_id' => $lessonA->id, 'title' => 'Mock A', 'blueprint' => [['count' => 1, 'concept' => 'topic-a']], 'time_limit_minutes' => 20, 'max_attempts' => 3, 'status' => 'published', 'test_series_id' => $series->id]);
    $quizB = Quiz::create(['lesson_id' => $lessonB->id, 'title' => 'Mock B', 'blueprint' => [['count' => 1, 'concept' => 'topic-b']], 'time_limit_minutes' => 20, 'max_attempts' => 3, 'status' => 'published', 'test_series_id' => $series->id]);

    $questionA = Question::create(['course_id' => $course->id, 'type' => 'mcq', 'stem' => 'QA', 'options' => ['3', '4'], 'correct_answer' => '4', 'difficulty' => 'easy', 'concept' => 'topic-a', 'status' => 'approved', 'source' => 'manual']);
    $questionB = Question::create(['course_id' => $course->id, 'type' => 'mcq', 'stem' => 'QB', 'options' => ['3', '4'], 'correct_answer' => '4', 'difficulty' => 'easy', 'concept' => 'topic-b', 'status' => 'approved', 'source' => 'manual']);

    $topper = User::factory()->create(['tenant_id' => $tenant->id]);
    $topper->assignRole('student');
    $runnerUp = User::factory()->create(['tenant_id' => $tenant->id]);
    $runnerUp->assignRole('student');

    $service = app(QuizAttemptService::class);

    // Topper: both quizzes correct.
    $attempt = $service->start($quizA, $topper);
    $service->submit($attempt, [$questionA->id => '4']);
    $attempt = $service->start($quizB, $topper);
    $service->submit($attempt, [$questionB->id => '4']);

    // Runner-up: one correct, one wrong.
    $attempt = $service->start($quizA, $runnerUp);
    $service->submit($attempt, [$questionA->id => '4']);
    $attempt = $service->start($quizB, $runnerUp);
    $service->submit($attempt, [$questionB->id => '3']);

    $rankings = app(TestSeriesRankingService::class)->rankingsFor($series, $topper);

    $topperRow = $rankings->firstWhere('user_id', $topper->id);
    $runnerUpRow = $rankings->firstWhere('user_id', $runnerUp->id);

    expect($topperRow['total_score'])->toBe(2.0)
        ->and($topperRow['rank'])->toBe(1)
        ->and($topperRow['percentile'])->toBe(50.0);

    expect($runnerUpRow['total_score'])->toBe(1.0)
        ->and($runnerUpRow['rank'])->toBe(2)
        ->and($runnerUpRow['percentile'])->toBe(0.0);

    // The runner-up's own results view shows only their own standing.
    Livewire::actingAs($runnerUp)
        ->test(\App\Livewire\TestSeriesResults::class, ['series' => $series])
        ->assertSee('rank: 2 / 2')
        ->assertDontSee($topper->name);
});
