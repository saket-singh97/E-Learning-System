<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\LessonPlayer;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function heartbeatLesson(): array
{
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
    ]);

    Storage::fake('local');
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lesson, $student];
}

it('records a heartbeat chunk for the authenticated user', function () {
    [$tenant, $lesson, $student] = heartbeatLesson();

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->actingAs($student)
        ->postJson($url, ['start_s' => 0, 'end_s' => 10])
        ->assertOk();

    $event = VideoWatchEvent::sole();
    expect($event->user_id)->toBe($student->id);
    expect($event->lesson_id)->toBe($lesson->id);
    expect($event->tenant_id)->toBe($tenant->id);
    expect($event->start_s)->toBe(0);
    expect($event->end_s)->toBe(10);
});

it('captures the reported video duration onto the lesson the first time it arrives', function () {
    [, $lesson, $student] = heartbeatLesson();

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->actingAs($student)
        ->postJson($url, ['start_s' => 0, 'end_s' => 10, 'duration_s' => 200])
        ->assertOk();

    expect($lesson->fresh()->video_duration_seconds)->toBe(200);
});

it('does not overwrite an already-known video duration on a later heartbeat', function () {
    [, $lesson, $student] = heartbeatLesson();
    $lesson->update(['video_duration_seconds' => 200]);

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->actingAs($student)
        ->postJson($url, ['start_s' => 10, 'end_s' => 20, 'duration_s' => 999])
        ->assertOk();

    expect($lesson->fresh()->video_duration_seconds)->toBe(200);
});

it('accepts a heartbeat with no duration_s at all, leaving the lessons duration untouched', function () {
    [, $lesson, $student] = heartbeatLesson();

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->actingAs($student)
        ->postJson($url, ['start_s' => 0, 'end_s' => 10])
        ->assertOk();

    expect($lesson->fresh()->video_duration_seconds)->toBeNull();
});

it('rejects a heartbeat from a guest', function () {
    [$tenant, $lesson] = heartbeatLesson();

    $url = URL::route('watch.heartbeat', ['subdomain' => 'physics', 'lesson' => $lesson->id]);

    $this->postJson($url, ['start_s' => 0, 'end_s' => 10])
        ->assertUnauthorized();

    expect(VideoWatchEvent::count())->toBe(0);
});

it('resumes the player at the last recorded end_s for that user and lesson', function () {
    [$tenant, $lesson, $student] = heartbeatLesson();

    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);

    Livewire::actingAs($student)
        ->test(LessonPlayer::class, ['lesson' => $lesson])
        ->assertSet('resumeAt', 10);
});
