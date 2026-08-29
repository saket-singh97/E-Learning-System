<?php

use App\Domains\AI\Livewire\FormulaSheet;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
});

it('shows the chapters revision card for a published course', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [['name' => 'Velocity', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    Livewire::test(FormulaSheet::class, ['section' => $section])->assertSee('Velocity');
});

it('404s a guest for a draft courses chapter', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'draft']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    Livewire::test(FormulaSheet::class, ['section' => $section])->assertStatus(404);
});
