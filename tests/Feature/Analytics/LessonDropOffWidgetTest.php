<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Widgets\LessonDropOffWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function dropOffWidgetLessonFixture(): array
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
        'video_duration_seconds' => 200,
    ]);

    return [$tenant, $lesson];
}

it('shows the correct drop-off percentage and affected student count', function () {
    [$tenant, $lesson] = dropOffWidgetLessonFixture();

    foreach ([121, 123, 129] as $lastEnd) {
        $student = User::factory()->create(['tenant_id' => $tenant->id]);
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => $lastEnd]);
    }

    Livewire::test(LessonDropOffWidget::class)
        ->set('record', $lesson)
        ->assertSee('60% through the video')
        ->assertSee('3 students stopped here');
});

it('shows the no-significant-drop-off state when every student finishes', function () {
    [$tenant, $lesson] = dropOffWidgetLessonFixture();

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 200]);

    Livewire::test(LessonDropOffWidget::class)
        ->set('record', $lesson)
        ->assertSee('No significant drop-off');
});
