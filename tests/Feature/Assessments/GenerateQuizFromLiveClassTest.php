<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ingestibleLessonOfType(Tenant $tenant, string $type): Lesson
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    return Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => $type,
        'position' => 0,
        'video_disk_path' => "videos/{$tenant->id}/lecture.mp4",
        'video_status' => 'ready',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('creates a draft quiz from seed questions once a live class finishes ingesting', function () {
    $lesson = ingestibleLessonOfType($this->tenant, 'live_session');

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    $quiz = Quiz::where('lesson_id', $lesson->id)->sole();

    expect($quiz->status)->toBe('draft')
        ->and($quiz->blueprint)->toBe([['count' => 1]]);
});

it('does not create a quiz for a non-live-session lessons ingestion', function () {
    $lesson = ingestibleLessonOfType($this->tenant, 'video');

    event(new LessonVideoReady($lesson->id, $this->tenant->id));

    expect(Quiz::where('lesson_id', $lesson->id)->exists())->toBeFalse();
});
