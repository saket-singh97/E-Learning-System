<?php

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Domains\Content\Filament\Resources\Courses\Pages\EditCourse;
use App\Domains\Content\Filament\Resources\Courses\RelationManagers\SectionsRelationManager;
use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use App\Domains\Content\Filament\Resources\CourseSections\Pages\EditCourseSection;
use App\Domains\Content\Filament\Resources\CourseSections\RelationManagers\LessonsRelationManager;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
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
});

it('assigns sections created without dragging a strictly increasing position, not the column default of 0', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    $this->actingAs($this->instructor)
        ->get(CourseResource::getUrl('edit', ['record' => $course, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    $relationManager = Livewire::test(SectionsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => EditCourse::class,
    ]);

    $relationManager->callTableAction('create', data: ['title' => 'Kinematics'])->assertHasNoTableActionErrors();
    $relationManager->callTableAction('create', data: ['title' => 'Dynamics'])->assertHasNoTableActionErrors();
    $relationManager->callTableAction('create', data: ['title' => 'Thermodynamics'])->assertHasNoTableActionErrors();

    $positions = $course->fresh()->sections()->orderBy('id')->pluck('position')->all();

    expect($positions)->toBe([1, 2, 3])
        ->and($course->fresh()->sections->pluck('title')->all())->toBe(['Kinematics', 'Dynamics', 'Thermodynamics']);
});

it('assigns lessons created without dragging a strictly increasing position, not the column default of 0', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(CourseSectionResource::getUrl('edit', ['record' => $section, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    $relationManager = Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $section,
        'pageClass' => EditCourseSection::class,
    ]);

    $relationManager->callTableAction('create', data: ['title' => 'Speed', 'type' => 'text'])->assertHasNoTableActionErrors();
    $relationManager->callTableAction('create', data: ['title' => 'Velocity', 'type' => 'text'])->assertHasNoTableActionErrors();

    $positions = $section->fresh()->lessons()->orderBy('id')->pluck('position')->all();

    expect($positions)->toBe([1, 2])
        ->and($section->fresh()->lessons->pluck('title')->all())->toBe(['Speed', 'Velocity']);
});
