<?php

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Courses\Pages\EditCourse;
use App\Domains\Content\Filament\Resources\Courses\RelationManagers\SyllabusTopicsRelationManager;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\SyllabusTopic;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Mechanics', 'position' => 0]);
    $this->lessonA = Lesson::create(['course_section_id' => $section->id, 'title' => 'Newton I', 'type' => 'text', 'position' => 0]);
    $this->lessonB = Lesson::create(['course_section_id' => $section->id, 'title' => 'Newton II', 'type' => 'text', 'position' => 1]);

    $this->actingAs($this->instructor)
        ->get(CourseResource::getUrl('edit', ['record' => $this->course, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('creates a syllabus topic mapped to lessons, sequenced after existing topics', function () {
    SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 1]);

    Livewire::test(SyllabusTopicsRelationManager::class, [
        'ownerRecord' => $this->course,
        'pageClass' => EditCourse::class,
    ])->callTableAction('create', data: [
        'board_or_exam' => 'CBSE Class 10',
        'title' => "Newton's Laws",
        'lessons' => [$this->lessonA->id, $this->lessonB->id],
    ])->assertHasNoTableActionErrors();

    $topic = SyllabusTopic::where('title', "Newton's Laws")->firstOrFail();

    expect($topic->sequence)->toBe(2)
        ->and($topic->lessons()->pluck('lessons.id')->sort()->values()->all())
        ->toBe([$this->lessonA->id, $this->lessonB->id]);
});

it('detaches a lesson by editing the mapping', function () {
    $topic = SyllabusTopic::create(['course_id' => $this->course->id, 'board_or_exam' => 'CBSE Class 10', 'title' => 'Kinematics', 'sequence' => 0]);
    $topic->lessons()->sync([$this->lessonA->id, $this->lessonB->id]);

    Livewire::test(SyllabusTopicsRelationManager::class, [
        'ownerRecord' => $this->course,
        'pageClass' => EditCourse::class,
    ])->callTableAction('edit', $topic, data: [
        'board_or_exam' => 'CBSE Class 10',
        'title' => 'Kinematics',
        'lessons' => [$this->lessonA->id],
    ])->assertHasNoTableActionErrors();

    expect($topic->lessons()->pluck('lessons.id')->all())->toBe([$this->lessonA->id]);
});

it('rejects a lesson id belonging to a different course in the same tenant', function () {
    $otherCourse = Course::create(['title' => 'Chemistry 101', 'slug' => 'chemistry-101', 'status' => 'published']);
    $otherSection = CourseSection::create(['course_id' => $otherCourse->id, 'title' => 'Bonds', 'position' => 0]);
    $foreignLesson = Lesson::create(['course_section_id' => $otherSection->id, 'title' => 'Ionic Bonds', 'type' => 'text', 'position' => 0]);

    Livewire::test(SyllabusTopicsRelationManager::class, [
        'ownerRecord' => $this->course,
        'pageClass' => EditCourse::class,
    ])->callTableAction('create', data: [
        'board_or_exam' => 'CBSE Class 10',
        'title' => 'Kinematics',
        'lessons' => [$foreignLesson->id],
    ])->assertHasTableActionErrors(['lessons']);

    expect(SyllabusTopic::where('title', 'Kinematics')->exists())->toBeFalse();
});

it('rejects a lesson id belonging to another tenant entirely', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $foreignCourse = Course::create(['title' => 'Foreign Course', 'slug' => 'foreign-course', 'status' => 'published']);
    $foreignSection = CourseSection::create(['course_id' => $foreignCourse->id, 'title' => 'Foreign', 'position' => 0]);
    $foreignLesson = Lesson::create(['course_section_id' => $foreignSection->id, 'title' => 'Foreign Lesson', 'type' => 'text', 'position' => 0]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(SyllabusTopicsRelationManager::class, [
        'ownerRecord' => $this->course,
        'pageClass' => EditCourse::class,
    ])->callTableAction('create', data: [
        'board_or_exam' => 'CBSE Class 10',
        'title' => 'Kinematics',
        'lessons' => [$foreignLesson->id],
    ])->assertHasTableActionErrors(['lessons']);
});
