<?php

use App\Domains\Analytics\Services\RewatchHeatmapService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rewatchLessonFixture(): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_duration_seconds' => 120,
    ]);

    return [$tenant, $lesson];
}

it('ranks a heavily-rewatched window highest when more distinct students overlap it than any other window', function () {
    [$tenant, $lesson] = rewatchLessonFixture();

    $studentA = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $studentA->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 120]);

    // Two more students only ever rewatch the 90-100s window.
    $studentB = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $studentB->id, 'lesson_id' => $lesson->id, 'start_s' => 90, 'end_s' => 100]);

    $studentC = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $studentC->id, 'lesson_id' => $lesson->id, 'start_s' => 90, 'end_s' => 100]);

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($lesson);

    $top = $heatmap->first();

    expect($top['bucket_start_s'])->toBe(90)
        ->and($top['bucket_end_s'])->toBe(100)
        ->and($top['replay_count'])->toBe(3)
        ->and($heatmap->where('bucket_start_s', '!=', 90)->pluck('replay_count')->unique()->all())->toBe([1]);
});

it('counts a student rewatching the same window many times only once', function () {
    [$tenant, $lesson] = rewatchLessonFixture();
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (range(1, 5) as $i) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 10, 'end_s' => 20]);
    }

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($lesson);

    expect($heatmap)->toHaveCount(1)
        ->and($heatmap->first()['replay_count'])->toBe(1);
});

it('never spills a heartbeat ending exactly on a bucket boundary into the next bucket', function () {
    [$tenant, $lesson] = rewatchLessonFixture();
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 90, 'end_s' => 100]);

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($lesson);

    expect($heatmap->pluck('bucket_start_s')->sort()->values()->all())->toBe([90]);
});

it('returns an empty collection, not an error, for a lesson with no watch events', function () {
    [, $lesson] = rewatchLessonFixture();

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($lesson);

    expect($heatmap)->toBeEmpty();
});

it('never includes another lessons watch events', function () {
    [$tenant, $lesson] = rewatchLessonFixture();
    $section = CourseSection::first();
    $otherLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Other', 'type' => 'video', 'position' => 1, 'video_duration_seconds' => 60]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $otherLesson->id, 'start_s' => 0, 'end_s' => 60]);

    $heatmap = app(RewatchHeatmapService::class)->heatmapFor($lesson);

    expect($heatmap)->toBeEmpty();
});
