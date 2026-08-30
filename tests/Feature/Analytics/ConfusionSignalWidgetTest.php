<?php

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Widgets\ConfusionSignalWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->course = $course;
    $this->section = $section;

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructor->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('shows the empty state for a batch with no taught lessons yet', function () {
    Livewire::test(ConfusionSignalWidget::class)
        ->set('record', $this->batch)
        ->assertSee('No lessons taught yet');
});

it('shows the confusion line for a taught lesson with all three signals', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Entropy', 'type' => 'video', 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);

    DoubtChatMessage::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'question' => 'Why?', 'answer' => 'Because.', 'cited_chunk_idxs' => [0]]);
    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 40, 'end_s' => 50]);

    $quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);
    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Q?',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'source' => 'manual',
    ]);
    QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id, 'is_correct' => false, 'status' => 'auto_graded']);

    Livewire::test(ConfusionSignalWidget::class)
        ->set('record', $this->batch)
        ->assertSee('Entropy')
        ->assertSee('1 doubts')
        ->assertSee('rewatches of 0:40-0:50')
        ->assertSee('100% failed');
});

it('loads the owning instructors EditBatch page with the widget wired in', function () {
    // Footer widgets lazy-load after the initial page paint (confirmed
    // against BatchEngagementWidgetTest's own pattern), so this only
    // proves the page itself doesn't error with ConfusionSignalWidget
    // registered — its content is covered by the Livewire::test() cases
    // above, and the 404 ownership boundary by TeachPanelBatchScopingTest.
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Entropy', 'type' => 'video', 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});
