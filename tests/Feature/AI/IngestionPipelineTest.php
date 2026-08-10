<?php

use App\Domains\AI\Events\LessonArtifactFailed;
use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

class ThrowingChatDriver extends FakeAiDriver
{
    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        throw new RuntimeException('vendor timeout');
    }
}

function ingestibleLesson(Tenant $tenant): Lesson
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('runs the ingestion pipeline end-to-end against FakeAiDriver', function () {
    $lesson = ingestibleLesson($this->tenant);

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

it('fires LessonArtifactReady when ingestion completes', function () {
    Event::fake([LessonArtifactReady::class]);

    $lesson = ingestibleLesson($this->tenant);
    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    Event::assertDispatched(LessonArtifactReady::class);
});

it('marks the artifact failed and stops the chain when a job throws mid-pipeline', function () {
    app()->bind(AiProvider::class, ThrowingChatDriver::class);
    Event::fake([LessonArtifactFailed::class]);

    $lesson = ingestibleLesson($this->tenant);
    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $artifact = LessonArtifact::where('lesson_id', $lesson->id)->sole();

    expect($artifact->status)->toBe('failed')
        ->and($artifact->failure_reason)->toBe('vendor timeout')
        ->and($artifact->concepts)->toBeNull();

    // ChunkTranscript (before the throwing stage) still ran, so chunk rows
    // exist; EmbedLessonChunks (after it) must never run, so embeddings don't.
    $chunks = LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->get();
    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk->embedding)->toBeNull();
    }

    Event::assertDispatched(LessonArtifactFailed::class);
});

it('gives every re-ingestion the next version without touching the previous ready row', function () {
    $lesson = ingestibleLesson($this->tenant);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));
    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $versions = LessonArtifact::where('lesson_id', $lesson->id)->orderBy('version')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->version)->toBe(1)
        ->and($versions[0]->status)->toBe('ready')
        ->and($versions[1]->version)->toBe(2)
        ->and($versions[1]->status)->toBe('ready');
});
