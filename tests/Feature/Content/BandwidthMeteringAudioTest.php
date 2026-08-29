<?php

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('meters an audio-only stream at the audio files own size, not the videos', function () {
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, str_repeat('v', 5000));

    $audioPath = "lesson-audio/{$tenant->id}/{$lesson->id}.mp3";
    Storage::disk('local')->put($audioPath, 'tiny audio bytes');
    LessonAudioTrack::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'disk_path' => $audioPath]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $audioUrl = URL::temporarySignedRoute('watch.audio', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($audioUrl)->assertOk();

    expect(BandwidthUsageDaily::sole()->bytes_served)->toBe(strlen('tiny audio bytes'));
});

it('404s when the lesson has no ready audio track', function () {
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $audioUrl = URL::temporarySignedRoute('watch.audio', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($audioUrl)->assertStatus(404);

    expect(BandwidthUsageDaily::count())->toBe(0);
});
