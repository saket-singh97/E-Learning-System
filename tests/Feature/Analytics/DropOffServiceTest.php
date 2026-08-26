<?php

use App\Domains\Analytics\Services\DropOffService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dropOffLessonFixture(int $durationSeconds = 200): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid()]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create([
        'course_section_id' => $section->id,
        'title' => 'Velocity',
        'type' => 'video',
        'position' => 0,
        'video_duration_seconds' => $durationSeconds,
    ]);

    return [$tenant, $lesson];
}

function dropOffStudentStopsAt(int $tenantId, int $lessonId, int $lastEndSeconds): void
{
    $student = User::factory()->create(['tenant_id' => $tenantId]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lessonId, 'start_s' => 0, 'end_s' => $lastEndSeconds]);
}

it('reports the point where most students stop watching', function () {
    [$tenant, $lesson] = dropOffLessonFixture(200);

    // Three students all stop somewhere in the 120-130s bucket.
    dropOffStudentStopsAt($tenant->id, $lesson->id, 121);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 123);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 129);

    // One student stops much earlier — the minority bucket.
    dropOffStudentStopsAt($tenant->id, $lesson->id, 40);

    $dropOff = app(DropOffService::class)->dropOffPointFor($lesson);

    expect($dropOff)->toBe(120);
});

it('returns null when every student finishes the lesson (within the near-end tolerance)', function () {
    [$tenant, $lesson] = dropOffLessonFixture(200);

    dropOffStudentStopsAt($tenant->id, $lesson->id, 200);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 198);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 196);

    $dropOff = app(DropOffService::class)->dropOffPointFor($lesson);

    expect($dropOff)->toBeNull();
});

it('excludes finished students but still reports the point for those who dropped off', function () {
    [$tenant, $lesson] = dropOffLessonFixture(200);

    dropOffStudentStopsAt($tenant->id, $lesson->id, 200);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 197);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 60);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 61);

    $dropOff = app(DropOffService::class)->dropOffPointFor($lesson);

    expect($dropOff)->toBe(60);
});

it('returns null, not an error, when the lesson has no video_duration_seconds yet', function () {
    [$tenant, $lesson] = dropOffLessonFixture();
    $lesson->update(['video_duration_seconds' => null]);

    dropOffStudentStopsAt($tenant->id, $lesson->id, 40);

    $dropOff = app(DropOffService::class)->dropOffPointFor($lesson);

    expect($dropOff)->toBeNull();
});

it('returns null for a lesson with no watch events at all', function () {
    [, $lesson] = dropOffLessonFixture();

    $dropOff = app(DropOffService::class)->dropOffPointFor($lesson);

    expect($dropOff)->toBeNull();
});

it('reports the correct drop-off percentage of runtime and affected student count', function () {
    [$tenant, $lesson] = dropOffLessonFixture(200);

    dropOffStudentStopsAt($tenant->id, $lesson->id, 121);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 123);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 129);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 40);

    $details = app(DropOffService::class)->detailsFor($lesson);

    expect($details)->toBe([
        'point_s' => 120,
        'percentage_of_runtime' => 60,
        'students_affected' => 3,
    ]);
});

it('returns null details when every student finishes the lesson', function () {
    [$tenant, $lesson] = dropOffLessonFixture(200);

    dropOffStudentStopsAt($tenant->id, $lesson->id, 200);
    dropOffStudentStopsAt($tenant->id, $lesson->id, 198);

    expect(app(DropOffService::class)->detailsFor($lesson))->toBeNull();
});
