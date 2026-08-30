<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\CatchUpPlanService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('returns an empty plan for a fully caught-up student', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'L1', 'type' => 'text', 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);
    VideoWatchEvent::create(['lesson_id' => $lesson->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 10]);

    expect(app(CatchUpPlanService::class)->planFor($this->student, $this->batch))->toHaveCount(0);
});

it('excludes a taught lesson that has not been released to students yet', function () {
    $lesson = Lesson::create([
        'course_section_id' => $this->section->id,
        'title' => 'Not released',
        'type' => 'text',
        'position' => 0,
        'scheduled_at' => now()->addWeek(),
    ]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 0]);

    expect(app(CatchUpPlanService::class)->planFor($this->student, $this->batch))->toHaveCount(0);
});

it('excludes a lesson not yet taught (future session)', function () {
    $lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Future', 'type' => 'text', 'position' => 0]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $lesson->id, 'starts_at' => now()->addDay(), 'duration_minutes' => 60, 'position' => 0]);

    expect(app(CatchUpPlanService::class)->planFor($this->student, $this->batch))->toHaveCount(0);
});

it('lists incomplete taught lessons oldest-taught-first', function () {
    $older = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Older', 'type' => 'text', 'position' => 0]);
    $newer = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Newer', 'type' => 'text', 'position' => 1]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $newer->id, 'starts_at' => now()->subDay(), 'duration_minutes' => 60, 'position' => 1]);
    BatchSession::create(['batch_id' => $this->batch->id, 'lesson_id' => $older->id, 'starts_at' => now()->subDays(3), 'duration_minutes' => 60, 'position' => 0]);

    $plan = app(CatchUpPlanService::class)->planFor($this->student, $this->batch);

    expect($plan->pluck('id')->all())->toBe([$older->id, $newer->id]);
});
