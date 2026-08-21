<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * B10 (G6) — no new production code, per design doc decision 5:
 * StreamLessonController's existing canStream()/isPlayable() gate and
 * signed-URL route already cover a captured recording once
 * DownloadLiveRecording (B9) lands it on the lesson's video_disk_path —
 * same path a manually-uploaded video lesson already uses. This proves it
 * end to end rather than adding a parallel playback path.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
});

it('streams a live_session lesson through the existing signed-URL path once a recording lands on video_disk_path', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Live', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Q&A',
        'type' => 'live_session',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    // Same disk path DownloadLiveRecording (B9) writes to, and the same
    // convention a manually-uploaded video lesson already uses.
    $diskPath = "videos/{$tenant->id}/{$lesson->id}.mp4";
    Storage::disk('local')->put($diskPath, 'fake recorded bytes');
    $lesson->update(['video_disk_path' => $diskPath]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $url = URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($url)->assertOk();
});

it('404s a live_session lesson stream when no recording has landed yet', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-2', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Live', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Live Q&A (unrecorded)',
        'type' => 'live_session',
        'position' => 0,
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $url = URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($url)->assertNotFound();
});
