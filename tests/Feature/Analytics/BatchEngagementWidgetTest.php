<?php

use App\Domains\Analytics\Services\EngagementMetricsService;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Widgets\BatchEngagementWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function batchEngagementFixture(): array
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
        'video_duration_seconds' => 30,
    ]);

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'draft',
    ]);

    return [$tenant, $batch, $lesson];
}

function batchEngagementEnrol(Batch $batch, int $tenantId): User
{
    $student = User::factory()->create(['tenant_id' => $tenantId]);
    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    return $student;
}

it('computes correct average ratio and sustained_2x count across multiple students', function () {
    [$tenant, $batch, $lesson] = batchEngagementFixture();

    $studentA = batchEngagementEnrol($batch, $tenant->id);
    VideoWatchEvent::create(['user_id' => $studentA->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 30]);

    $studentB = batchEngagementEnrol($batch, $tenant->id);
    // Two fast heartbeats only — below the 3-event sustained_2x threshold, but real watch data.
    VideoWatchEvent::create(['user_id' => $studentB->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 15]);

    $result = app(EngagementMetricsService::class)->classEngagementFor($batch);

    expect($result['total_students'])->toBe(2)
        ->and($result['sample_size'])->toBe(2)
        ->and($result['avg_ratio'])->toBe(round((1.0 + 0.5) / 2, 2))
        ->and($result['sustained_2x_count'])->toBe(0);
});

it('flags a student with sustained 2x playback in the aggregate count', function () {
    [$tenant, $batch, $lesson] = batchEngagementFixture();
    $student = batchEngagementEnrol($batch, $tenant->id);

    foreach ([[0, 20], [20, 40], [40, 60]] as [$start, $end]) {
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => $start, 'end_s' => $end]);
    }

    $result = app(EngagementMetricsService::class)->classEngagementFor($batch);

    expect($result['sustained_2x_count'])->toBe(1);
});

it('returns a zeroed, not error, result for a batch with active students but no watch events yet', function () {
    [$tenant, $batch] = batchEngagementFixture();
    batchEngagementEnrol($batch, $tenant->id);

    $result = app(EngagementMetricsService::class)->classEngagementFor($batch);

    expect($result['sample_size'])->toBe(0)
        ->and($result['avg_ratio'])->toBe(0.0)
        ->and($result['total_students'])->toBe(1);
});

it('returns zero totals for a batch with no active enrolments at all', function () {
    [, $batch] = batchEngagementFixture();

    $result = app(EngagementMetricsService::class)->classEngagementFor($batch);

    expect($result)->toBe(['avg_ratio' => 0.0, 'sustained_2x_count' => 0, 'total_students' => 0, 'sample_size' => 0]);
});

it('widget shows the empty state for a batch with no watch events yet', function () {
    [$tenant, $batch] = batchEngagementFixture();
    batchEngagementEnrol($batch, $tenant->id);

    Livewire::test(BatchEngagementWidget::class)
        ->set('record', $batch)
        ->assertSee('No watch data yet');
});

it('widget shows aggregate numbers once watch data exists', function () {
    [$tenant, $batch, $lesson] = batchEngagementFixture();
    $student = batchEngagementEnrol($batch, $tenant->id);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 30]);

    Livewire::test(BatchEngagementWidget::class)
        ->set('record', $batch)
        ->assertSee('Avg engagement ratio')
        ->assertSee('100%')
        ->assertSee('Sustained 2x playback');
});
