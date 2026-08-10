<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidFileTypeException;
use App\Integrations\Drivers\FakeVideoDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $this->driver = new FakeVideoDriver;
});

it('stores an uploaded video at a tenant- and lesson-scoped path', function () {
    $file = UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4');

    $path = $this->driver->store($file, $this->lesson);

    expect($path)->toBe("videos/{$this->lesson->tenant_id}/{$this->lesson->id}.mp4");
    Storage::disk('local')->assertExists($path);
});

it('rejects a disallowed video extension and writes nothing', function () {
    $file = UploadedFile::fake()->create('lecture.exe', 100, 'application/octet-stream');

    expect(fn () => $this->driver->store($file, $this->lesson))
        ->toThrow(InvalidFileTypeException::class);

    Storage::disk('local')->assertDirectoryEmpty("videos/{$this->lesson->tenant_id}");
});

it('resolves a url for a stored video', function () {
    $file = UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4');
    $this->lesson->update(['video_disk_path' => $this->driver->store($file, $this->lesson)]);

    expect($this->driver->url($this->lesson->fresh()))->toContain("videos/{$this->lesson->tenant_id}/{$this->lesson->id}.mp4");
});

it('deletes a stored video', function () {
    $file = UploadedFile::fake()->create('lecture.mp4', 100, 'video/mp4');
    $path = $this->driver->store($file, $this->lesson);
    $this->lesson->update(['video_disk_path' => $path]);

    $this->driver->delete($this->lesson->fresh());

    Storage::disk('local')->assertMissing($path);
});
