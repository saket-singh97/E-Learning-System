<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeAiDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

/**
 * S5 B6: the first time this pipeline is ever exercised against a
 * non-video-type lesson. Mirrors IngestionPipelineTest's own
 * ingestibleLesson() fixture and assertions exactly, type swapped to
 * live_session, to prove the 4-job chain is genuinely type-agnostic (it
 * only reads video_disk_path/video_status, never Lesson::type) rather than
 * assuming that from reading the code.
 */
it('runs the ingestion pipeline end-to-end against a live_session lessons manually-attached recording', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Q&A',
        'type' => 'live_session',
        'position' => 0,
        'video_disk_path' => "videos/{$this->tenant->id}/recording.mp4",
        'video_status' => 'ready',
    ]);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();

    expect($artifact->status)->toBe('ready')
        ->and($artifact->version)->toBe(1)
        ->and($artifact->transcript)->not->toBeEmpty()
        ->and($artifact->concepts)->not->toBeEmpty()
        ->and($artifact->token_cost)->toHaveKeys(['in', 'out', 'usd']);

    $chunks = LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->get();
    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk->embedding->toArray())->toHaveCount(FakeAiDriver::DIMENSIONS);
    }
});
