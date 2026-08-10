<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('builds a course tree of sections and lessons', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);

    expect($course->sections)->toHaveCount(1);
    expect($section->course->id)->toBe($course->id);
    expect($section->lessons)->toHaveCount(1);
    expect($lesson->section->id)->toBe($section->id);
});

it('orders sections and lessons by position and reflects reordering', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $first = CourseSection::create(['course_id' => $course->id, 'title' => 'A', 'position' => 0]);
    $second = CourseSection::create(['course_id' => $course->id, 'title' => 'B', 'position' => 1]);

    expect($course->fresh()->sections->pluck('id')->all())->toBe([$first->id, $second->id]);

    $first->update(['position' => 2]);

    expect($course->fresh()->sections->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('hides a deleted course and restores it', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);

    $course->delete();

    expect(Course::find($course->id))->toBeNull();
    expect(Course::withTrashed()->find($course->id))->not->toBeNull();

    $course->restore();

    expect(Course::find($course->id))->not->toBeNull();
});

it('lets a new course reuse the slug of a soft-deleted one', function () {
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $course->delete();

    $recreated = Course::create(['title' => 'Mechanics Again', 'slug' => 'mechanics']);

    expect($recreated->exists)->toBeTrue();
});

it('lets an instructor reach the course list in the teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/courses')
        ->assertOk();
});
