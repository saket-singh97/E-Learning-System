<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Content\Services\PrerequisiteGraph;
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

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->first = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Intro', 'type' => 'video', 'position' => 0, 'video_status' => 'ready', 'video_duration_seconds' => 100]);
    $this->second = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Next up', 'type' => 'text', 'position' => 1, 'text_body' => 'Hello']);

    app(PrerequisiteGraph::class)->addPrerequisite($this->second, $this->first);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->access = app(LessonAccess::class);
});

it('denies the next lesson while its prerequisite is incomplete', function () {
    expect($this->access->canStream($this->second, $this->student))->toBeFalse();
});

it('flips to allowed once the prerequisite lesson is completed', function () {
    VideoWatchEvent::create(['lesson_id' => $this->first->id, 'user_id' => $this->student->id, 'start_s' => 0, 'end_s' => 95]);

    expect($this->access->canStream($this->second, $this->student))->toBeTrue();
});

it('does not gate a lesson with no prerequisites of its own', function () {
    expect($this->access->canStream($this->first, $this->student))->toBeTrue();
});

it('bypasses the prerequisite gate for staff previewing their own content', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    expect($this->access->canStream($this->second, $instructor))->toBeTrue();
});
