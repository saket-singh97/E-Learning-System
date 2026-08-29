<?php

use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AudioExtractionProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function audioLesson(Tenant $tenant): Lesson
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('creates a ready audio track against the fake driver once a lessons video is ready', function () {
    Storage::fake('local');

    $lesson = audioLesson($this->tenant);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $track = LessonAudioTrack::where('lesson_id', $lesson->id)->sole();

    expect($track->status)->toBe('ready')
        ->and($track->disk_path)->toBe("lesson-audio/{$this->tenant->id}/{$lesson->id}.mp3");

    Storage::disk('local')->assertExists($track->disk_path);
});

it('marks the track failed instead of fatally erroring when extraction throws', function () {
    Storage::fake('local');

    app()->bind(AudioExtractionProvider::class, fn () => new class implements AudioExtractionProvider
    {
        public function extract(Lesson $lesson): array
        {
            throw new RuntimeException('forced failure');
        }
    });

    $lesson = audioLesson($this->tenant);

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $track = LessonAudioTrack::where('lesson_id', $lesson->id)->sole();
    expect($track->status)->toBe('failed');
});
