<?php

use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use App\Domains\Content\Filament\Resources\CourseSections\Pages\EditCourseSection;
use App\Domains\Content\Filament\Resources\CourseSections\RelationManagers\LessonsRelationManager;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $this->section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(CourseSectionResource::getUrl('edit', ['record' => $this->section, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('uploads an mp4 to a video lesson and marks it ready', function () {
    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4'),
        ])
        ->assertHasNoTableActionErrors();

    $lesson = Lesson::sole();
    expect($lesson->video_status)->toBe('ready');
    expect($lesson->video_disk_path)->toBe("videos/{$this->tenant->id}/{$lesson->id}.mp4");
    Storage::disk('local')->assertExists($lesson->video_disk_path);
});

it('uploads a recording to a live_session lesson through the same video field', function () {
    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Live Q&A',
            'type' => 'live_session',
            'video_upload' => UploadedFile::fake()->create('recording.mp4', 100, 'video/mp4'),
        ])
        ->assertHasNoTableActionErrors();

    $lesson = Lesson::sole();
    expect($lesson->type)->toBe('live_session')
        ->and($lesson->video_status)->toBe('ready')
        ->and($lesson->video_disk_path)->toBe("videos/{$this->tenant->id}/{$lesson->id}.mp4");
    Storage::disk('local')->assertExists($lesson->video_disk_path);
});

it('clears a previously-known video duration when the file is replaced', function () {
    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4'),
        ])
        ->assertHasNoTableActionErrors();

    $lesson = Lesson::sole();
    // Simulate a prior heartbeat having already reported the old file's duration.
    $lesson->update(['video_duration_seconds' => 300]);

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('edit', $lesson, data: [
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture-v2.webm', 100, 'video/webm'),
        ])
        ->assertHasNoTableActionErrors();

    expect($lesson->fresh()->video_duration_seconds)->toBeNull();
});

it('rejects a non-video extension with a field error, writing nothing', function () {
    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $this->section,
        'pageClass' => EditCourseSection::class,
    ])
        ->callTableAction('create', data: [
            'title' => 'Velocity',
            'type' => 'video',
            'video_upload' => UploadedFile::fake()->create('lecture.exe', 100),
        ])
        ->assertHasTableActionErrors(['video_upload']);

    expect(Lesson::count())->toBe(0);
});
