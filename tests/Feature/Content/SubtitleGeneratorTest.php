<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\SubtitleGenerator;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function subtitleLesson(Tenant $tenant): Lesson
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

it('renders SRT and VTT cues from lesson artifact chunk rows', function () {
    $lesson = subtitleLesson($this->tenant);

    $artifact = LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => $lesson->video_disk_path,
        'status' => 'ready',
    ]);

    LessonArtifactChunk::create([
        'lesson_artifact_id' => $artifact->id,
        'chunk_idx' => 0,
        'start_s' => 0,
        'end_s' => 5.5,
        'text' => 'Velocity is the rate of change of position.',
    ]);

    LessonArtifactChunk::create([
        'lesson_artifact_id' => $artifact->id,
        'chunk_idx' => 1,
        'start_s' => 5.5,
        'end_s' => 3661.25,
        'text' => 'It is a vector quantity.',
    ]);

    $generator = app(SubtitleGenerator::class);

    expect($generator->toSrt($artifact))->toBe(
        "1\n00:00:00,000 --> 00:00:05,500\nVelocity is the rate of change of position.\n\n".
        "2\n00:00:05,500 --> 01:01:01,250\nIt is a vector quantity."
    );

    expect($generator->toVtt($artifact))->toBe(
        "WEBVTT\n\n".
        "00:00:00.000 --> 00:00:05.500\nVelocity is the rate of change of position.\n\n".
        "00:00:05.500 --> 01:01:01.250\nIt is a vector quantity."
    );
});

it('generates and stores a VTT file once ingestion produces a ready artifact', function () {
    Storage::fake('local');

    $lesson = subtitleLesson($this->tenant);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $lesson->refresh();

    expect($lesson->subtitle_status)->toBe('ready')
        ->and($lesson->subtitle_disk_path)->toBe("subtitles/{$this->tenant->id}/{$lesson->id}.vtt");

    Storage::disk('local')->assertExists($lesson->subtitle_disk_path);
    expect(Storage::disk('local')->get($lesson->subtitle_disk_path))->toStartWith('WEBVTT');
});
