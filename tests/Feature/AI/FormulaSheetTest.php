<?php

use App\Domains\AI\Livewire\FormulaSheet;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $this->tenant = $tenant;
});

it('shows the chapters revision card to a student who can actually stream the lesson', function () {
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

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    Livewire::actingAs($student)
        ->test(FormulaSheet::class, ['section' => $section])
        ->assertSee('Velocity');
});

it('404s a guest for a draft courses chapter', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'draft']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    Livewire::test(FormulaSheet::class, ['section' => $section])->assertStatus(404);
});

it('never leaks a published courses real mined content to a guest (S22 B13 review pass)', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    LessonArtifact::create([
        'lesson_id' => $lesson->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'concepts' => [['name' => 'SECRET_PAID_CONCEPT', 'confidence' => 0.9, 'chunk_idxs' => [0]]],
    ]);

    // Page itself still loads for a guest (same posture as the syllabus
    // tree), but the mined content must not leak through it.
    Livewire::test(FormulaSheet::class, ['section' => $section])
        ->assertStatus(200)
        ->assertDontSee('SECRET_PAID_CONCEPT');
});
