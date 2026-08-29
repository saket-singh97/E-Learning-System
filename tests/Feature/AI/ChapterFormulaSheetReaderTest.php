<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\ChapterFormulaSheetReader;
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
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
});

it('aggregates distinct concept names across every lesson in the chapter', function () {
    $lesson1 = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $lesson2 = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Acceleration', 'type' => 'video', 'position' => 1]);

    LessonArtifact::create([
        'lesson_id' => $lesson1->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [['name' => 'Velocity', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);
    LessonArtifact::create([
        'lesson_id' => $lesson2->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        // Duplicate concept name across lessons must dedupe, not repeat.
        'concepts' => [
            ['name' => 'Velocity', 'confidence' => 0.8, 'chunk_idxs' => [0]],
            ['name' => 'Acceleration', 'confidence' => 0.9, 'chunk_idxs' => [1]],
        ],
    ]);

    $concepts = app(ChapterFormulaSheetReader::class)->forSection($this->section->fresh(['lessons']));

    expect($concepts)->toBe(['Velocity', 'Acceleration']);
});

it('returns an empty list, not an error, when no lesson in the chapter has extracted concepts yet', function () {
    Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    expect(app(ChapterFormulaSheetReader::class)->forSection($this->section->fresh(['lessons'])))->toBe([]);
});
