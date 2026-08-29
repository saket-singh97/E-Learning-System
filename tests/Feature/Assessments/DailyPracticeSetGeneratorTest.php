<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Assessments\Models\DailyPracticeSet;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Services\DailyPracticeSetGenerator;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dppLesson(CourseSection $section, string $title, array $seedQuestions): Lesson
{
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => $title,
        'type' => 'live_session',
        'position' => 0,
    ]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'seed_questions' => $seedQuestions,
    ]);

    return $lesson;
}

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->seed = [['type' => 'mcq', 'stem' => 'Q?', 'options' => ['A', 'B'], 'answer' => 'A']];
});

it('aggregates seed questions from every lesson taught up to the practice date', function () {
    $lesson1 = dppLesson($this->section, 'Velocity', $this->seed);
    $lesson2 = dppLesson($this->section, 'Acceleration', $this->seed);

    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson1->id, 'starts_at' => now()->subDays(2), 'duration_minutes' => 60, 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson2->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 1]);

    $set = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now());

    expect($set)->toBeInstanceOf(DailyPracticeSet::class)
        ->and($set->batch_id)->toBe($this->batch->id)
        ->and($set->question_ids)->toHaveCount(2);

    expect(Question::whereIn('id', $set->question_ids)->pluck('lesson_id')->sort()->values()->all())
        ->toBe(collect([$lesson1->id, $lesson2->id])->sort()->values()->all());
});

it('never includes a lesson taught after the practice date cutoff', function () {
    $taught = dppLesson($this->section, 'Velocity', $this->seed);
    $future = dppLesson($this->section, 'Momentum', $this->seed);

    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $taught->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $future->id, 'starts_at' => now()->addDay(), 'duration_minutes' => 60, 'position' => 1]);

    $set = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now());

    expect($set->question_ids)->toHaveCount(1);
    expect(Question::whereIn('id', $set->question_ids)->first()->lesson_id)->toBe($taught->id);
});

it('is idempotent for the same batch and date, returning the same row without regenerating questions', function () {
    $lesson = dppLesson($this->section, 'Velocity', $this->seed);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);

    $first = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now());
    $questionCountAfterFirst = Question::count();

    $second = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now());

    expect($second->id)->toBe($first->id)
        ->and(DailyPracticeSet::count())->toBe(1)
        ->and(Question::count())->toBe($questionCountAfterFirst);
});

it('reuses the same lessons already-promoted questions across different practice dates instead of duplicating them (S22 B13 review pass)', function () {
    // The cumulative "taught so far" window means a lesson taught 3 days
    // ago contributes to every one of today's, yesterday's, and 3-days-ago's
    // own DailyPracticeSet — generateFor()'s own idempotency only covers
    // the same (batch, date) pair, so QuestionGenerator::fromSeedQuestions()
    // itself must be the thing that stops re-promoting fresh duplicate
    // Question rows for a lesson already covered by an earlier date.
    $lesson = dppLesson($this->section, 'Velocity', $this->seed);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDays(3), 'duration_minutes' => 60, 'position' => 0]);

    $day1 = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now()->subDays(2));
    $day2 = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now()->subDay());
    $day3 = app(DailyPracticeSetGenerator::class)->generateFor($this->batch, now());

    expect(Question::where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and($day1->question_ids)->toBe($day2->question_ids)
        ->and($day2->question_ids)->toBe($day3->question_ids);
});
