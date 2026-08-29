<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\ChapterMarkerReader;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
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

it('maps each concept to its earliest chunk start_s, sorted by time', function () {
    $artifact = LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [
            ['name' => 'Carnot cycle', 'confidence' => 0.9, 'chunk_idxs' => [2]],
            ['name' => 'Velocity', 'confidence' => 0.9, 'chunk_idxs' => [0, 1]],
        ],
    ]);
    $artifact->chunkRows()->createMany([
        ['chunk_idx' => 0, 'start_s' => 0.0, 'end_s' => 10.0, 'text' => 'a'],
        ['chunk_idx' => 1, 'start_s' => 10.0, 'end_s' => 20.0, 'text' => 'b'],
        ['chunk_idx' => 2, 'start_s' => 34.5, 'end_s' => 44.0, 'text' => 'c'],
    ]);

    $markers = app(ChapterMarkerReader::class)->forLesson($this->lesson);

    expect($markers)->toBe([
        ['label' => 'Velocity', 'start_s' => 0.0],
        ['label' => 'Carnot cycle', 'start_s' => 34.5],
    ]);
});

it('returns an empty list, not an error, when no concepts are extracted yet', function () {
    LessonArtifact::create([
        'lesson_id' => $this->lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
    ]);

    expect(app(ChapterMarkerReader::class)->forLesson($this->lesson))->toBe([]);
});

it('returns an empty list when no ready artifact exists at all', function () {
    expect(app(ChapterMarkerReader::class)->forLesson($this->lesson))->toBe([]);
});
