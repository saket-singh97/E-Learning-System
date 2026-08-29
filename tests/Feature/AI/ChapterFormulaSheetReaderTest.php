<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\ChapterFormulaSheetReader;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    // Reader-level unit test of aggregation/dedup — access-control itself
    // is covered separately in FormulaSheetTest.php — so gate with a
    // staff viewer (isVisibleTo()/canStream() both bypass for staff)
    // rather than also fixturing a published course + real enrolment here.
    $this->viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->viewer->assignRole('instructor');

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

    $concepts = app(ChapterFormulaSheetReader::class)->forSection($this->section->fresh(['lessons']), app(LessonAccess::class), $this->viewer);

    expect($concepts)->toBe(['Velocity', 'Acceleration']);
});

it('returns an empty list, not an error, when no lesson in the chapter has extracted concepts yet', function () {
    Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    expect(app(ChapterFormulaSheetReader::class)->forSection($this->section->fresh(['lessons']), app(LessonAccess::class), $this->viewer))->toBe([]);
});
