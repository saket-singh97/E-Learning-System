<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\LessonPlayer;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function audioToggleLesson(Tenant $tenant): Lesson
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_status' => 'ready',
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('shows the audio-only toggle only when a ready track exists', function () {
    $lesson = audioToggleLesson($this->tenant);

    LessonAudioTrack::create(['lesson_id' => $lesson->id, 'status' => 'ready', 'disk_path' => 'lesson-audio/x.mp3']);

    Livewire::test(LessonPlayer::class, ['lesson' => $lesson])
        ->assertSee('Audio-only mode');
});

it('hides the toggle when no track exists yet', function () {
    $lesson = audioToggleLesson($this->tenant);

    Livewire::test(LessonPlayer::class, ['lesson' => $lesson])
        ->assertDontSee('Audio-only mode');
});

it('hides the toggle while the track is still pending', function () {
    $lesson = audioToggleLesson($this->tenant);

    LessonAudioTrack::create(['lesson_id' => $lesson->id, 'status' => 'pending']);

    Livewire::test(LessonPlayer::class, ['lesson' => $lesson])
        ->assertDontSee('Audio-only mode');
});
