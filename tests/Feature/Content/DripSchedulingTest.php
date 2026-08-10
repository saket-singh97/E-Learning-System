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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function drippedLesson(?string $scheduledAt): array
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
        'scheduled_at' => $scheduledAt,
    ]);
    $lesson->update(['video_disk_path' => "videos/{$tenant->id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    return [$lesson, $student, $instructor];
}

function watchUrlFor(Lesson $lesson): string
{
    return URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);
}

beforeEach(fn () => Storage::fake('local'));

it('excludes a future-scheduled lesson from the student-visible scope', function () {
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);

    $futureLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Future', 'type' => 'video', 'position' => 0, 'scheduled_at' => now()->addDay()]);
    $pastLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Past', 'type' => 'video', 'position' => 1, 'scheduled_at' => now()->subDay()]);
    $openLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Open', 'type' => 'video', 'position' => 2]);

    $visibleIds = Lesson::visibleToStudents()->pluck('id');

    expect($visibleIds)->not->toContain($futureLesson->id);
    expect($visibleIds)->toContain($pastLesson->id);
    expect($visibleIds)->toContain($openLesson->id);
});

it('404s a student streaming a lesson scheduled for the future', function () {
    [$lesson, $student] = drippedLesson(now()->addDay()->toDateTimeString());

    $this->actingAs($student)->get(watchUrlFor($lesson))->assertNotFound();
});

it('lets an instructor preview a future-scheduled lesson', function () {
    [$lesson, , $instructor] = drippedLesson(now()->addDay()->toDateTimeString());

    $this->actingAs($instructor)->get(watchUrlFor($lesson))->assertOk();
});

it('lets a student stream a lesson once its scheduled_at moves to the past', function () {
    [$lesson, $student] = drippedLesson(now()->addDay()->toDateTimeString());

    $lesson->update(['scheduled_at' => now()->subDay()]);

    $this->actingAs($student)->get(watchUrlFor($lesson))->assertOk();
});

it('badges a future-scheduled lesson in the teacher-facing table, not a past one', function () {
    test()->seed(RoleSeeder::class);
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    Lesson::create(['course_section_id' => $section->id, 'title' => 'Future', 'type' => 'video', 'position' => 0, 'scheduled_at' => now()->addDay()]);
    Lesson::create(['course_section_id' => $section->id, 'title' => 'Past', 'type' => 'video', 'position' => 1, 'scheduled_at' => now()->subDay()]);

    test()->actingAs($instructor)
        ->get(CourseSectionResource::getUrl('edit', ['record' => $section, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(LessonsRelationManager::class, [
        'ownerRecord' => $section,
        'pageClass' => EditCourseSection::class,
    ])
        ->assertSee('Scheduled:');
});
