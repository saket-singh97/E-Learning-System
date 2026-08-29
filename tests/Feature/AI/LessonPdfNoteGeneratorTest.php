<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonPdfNote;
use App\Domains\AI\Services\LessonPdfNoteGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
    ]);
});

it('generates a pdf from a lessons ready artifact', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'transcript' => 'Velocity is the rate of change of displacement.',
    ]);

    $note = app(LessonPdfNoteGenerator::class)->generate($this->lesson);

    expect($note)->toBeInstanceOf(LessonPdfNote::class)
        ->and($note->lesson_id)->toBe($this->lesson->id)
        ->and($note->status)->toBe('ready')
        ->and($note->disk_path)->not->toBeNull()
        ->and($note->generated_at)->not->toBeNull();

    Storage::disk('local')->assertExists($note->disk_path);
});

it('leaves a still-processing placeholder row when no ready artifact exists', function () {
    $note = app(LessonPdfNoteGenerator::class)->generate($this->lesson);

    expect($note->status)->toBe('pending')
        ->and($note->disk_path)->toBeNull();

    expect(LessonPdfNote::count())->toBe(1);
});

it('overwrites the same row on regeneration instead of creating a new one', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'transcript' => 'First pass transcript.',
    ]);

    $first = app(LessonPdfNoteGenerator::class)->generate($this->lesson);

    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 2,
        'source_asset_version' => 'v2',
        'status' => 'ready',
        'transcript' => 'Re-ingested, updated transcript.',
    ]);

    $second = app(LessonPdfNoteGenerator::class)->generate($this->lesson);

    expect(LessonPdfNote::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->status)->toBe('ready');
});
