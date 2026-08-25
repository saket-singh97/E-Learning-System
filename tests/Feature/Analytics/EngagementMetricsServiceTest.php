<?php

use App\Domains\Analytics\Services\EngagementMetricsService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function engagementFixture(int $durationSeconds = 600): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_duration_seconds' => $durationSeconds,
    ]);

    return [$tenant, $student, $lesson];
}

it('scores near-1.0 engagement ratio for a normal full watch', function () {
    [, $student, $lesson] = engagementFixture(30);

    foreach ([[0, 10], [10, 20], [20, 30]] as [$start, $end]) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => $start, 'end_s' => $end]);
    }

    $ratio = app(EngagementMetricsService::class)->ratioFor($student, $lesson);

    expect($ratio)->toBe(1.0);
});

it('scores near-zero engagement ratio for a background-tab session with a long wall-clock span but almost no real watched seconds', function () {
    [, $student, $lesson] = engagementFixture(600);

    // Ten heartbeats (100s of real time) but the video barely advances each time.
    for ($i = 0; $i < 10; $i++) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => $i, 'end_s' => $i + 1]);
    }

    $ratio = app(EngagementMetricsService::class)->ratioFor($student, $lesson);

    expect($ratio)->toBeLessThan(0.05);
});

it('caps engagement ratio at 1.0 when rewatching pushes covered seconds past the lessons own duration', function () {
    [, $student, $lesson] = engagementFixture(10);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);

    $ratio = app(EngagementMetricsService::class)->ratioFor($student, $lesson);

    expect($ratio)->toBe(1.0);
});

it('returns 0.0 ratio, not an error, when the lesson has no video_duration_seconds yet', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $course = Course::create(['title' => 'C', 'slug' => 'c-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'S', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'L', 'type' => 'video', 'position' => 0]);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 5]);

    $ratio = app(EngagementMetricsService::class)->ratioFor($student, $lesson);

    expect($ratio)->toBe(0.0);
});

it('flags sustained_2x true when several heartbeats each cover roughly double the 10s interval', function () {
    [, $student, $lesson] = engagementFixture(600);

    // Each heartbeat advances 20 video-seconds over the fixed 10s wall-clock window: 2x speed.
    foreach ([[0, 20], [20, 40], [40, 60], [60, 80]] as [$start, $end]) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => $start, 'end_s' => $end]);
    }

    $profile = app(EngagementMetricsService::class)->speedProfileFor($student, $lesson);

    expect($profile['sustained_2x'])->toBeTrue()
        ->and($profile['fast_heartbeat_count'])->toBe(4)
        ->and($profile['total_heartbeats'])->toBe(4);
});

it('flags sustained_2x false for normal 1x playback', function () {
    [, $student, $lesson] = engagementFixture(600);

    foreach ([[0, 10], [10, 20], [20, 30], [30, 40]] as [$start, $end]) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => $start, 'end_s' => $end]);
    }

    $profile = app(EngagementMetricsService::class)->speedProfileFor($student, $lesson);

    expect($profile['sustained_2x'])->toBeFalse()
        ->and($profile['fast_heartbeat_count'])->toBe(0);
});

it('flags sustained_2x false for a single fast heartbeat, treating it as a one-off skip, not a sustained speed change', function () {
    [, $student, $lesson] = engagementFixture(600);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 10, 'end_s' => 30]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 30, 'end_s' => 40]);

    $profile = app(EngagementMetricsService::class)->speedProfileFor($student, $lesson);

    expect($profile['sustained_2x'])->toBeFalse()
        ->and($profile['fast_heartbeat_count'])->toBe(1);
});

it('never includes another students watch events in either metric', function () {
    [$tenant, $student, $lesson] = engagementFixture(30);
    $classmate = User::factory()->create(['tenant_id' => $tenant->id]);

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 5]);
    VideoWatchEvent::create(['user_id' => $classmate->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 30]);

    $service = app(EngagementMetricsService::class);

    expect($service->ratioFor($student, $lesson))->toBe(5 / 30)
        ->and($service->speedProfileFor($student, $lesson)['total_heartbeats'])->toBe(1);
});
