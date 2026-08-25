<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonRevisionNote;
use App\Domains\AI\Services\RevisionNotesGenerator;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\LessonArtifactNotReadyException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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

it('generates revision notes from a lessons ready artifact, pinned to that version', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'transcript' => 'Velocity is the rate of change of displacement.',
        'concepts' => [['name' => 'Velocity', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    $note = app(RevisionNotesGenerator::class)->generateFor($this->lesson);

    expect($note)->toBeInstanceOf(LessonRevisionNote::class)
        ->and($note->lesson_id)->toBe($this->lesson->id)
        ->and($note->source_artifact_version)->toBe(1)
        ->and($note->status)->toBe('ready')
        ->and($note->notes)->not->toBeEmpty()
        ->and($note->token_cost)->toHaveKeys(['in', 'out', 'usd']);
});

it('throws a clean, catchable exception instead of crashing when no ready artifact exists', function () {
    expect(fn () => app(RevisionNotesGenerator::class)->generateFor($this->lesson))
        ->toThrow(LessonArtifactNotReadyException::class);

    expect(LessonRevisionNote::count())->toBe(0);
});

it('still refuses gracefully when the only artifact has not finished ingesting', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'processing',
    ]);

    expect(fn () => app(RevisionNotesGenerator::class)->generateFor($this->lesson))
        ->toThrow(LessonArtifactNotReadyException::class);
});

it('creates a new row for a newer artifact version instead of overwriting the old notes', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'transcript' => 'First pass transcript.',
    ]);

    $first = app(RevisionNotesGenerator::class)->generateFor($this->lesson);

    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 2,
        'source_asset_version' => 'v2',
        'status' => 'ready',
        'transcript' => 'Re-ingested, updated transcript.',
    ]);

    $second = app(RevisionNotesGenerator::class)->generateFor($this->lesson);

    expect(LessonRevisionNote::count())->toBe(2)
        ->and($first->id)->not->toBe($second->id)
        ->and($first->source_artifact_version)->toBe(1)
        ->and($second->source_artifact_version)->toBe(2)
        ->and(LessonRevisionNote::find($first->id)->status)->toBe('ready');
});
