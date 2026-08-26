<?php

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Widgets\LessonRewatchHeatmapWidget;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rewatchWidgetLessonFixture(): array
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
        'video_duration_seconds' => 120,
    ]);

    return [$tenant, $lesson];
}

it('shows the top windows in the correct replay-count order with formatted timestamps', function () {
    [$tenant, $lesson] = rewatchWidgetLessonFixture();

    $studentA = User::factory()->create(['tenant_id' => $tenant->id]);
    VideoWatchEvent::create(['user_id' => $studentA->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 120]);

    foreach ([1, 2] as $n) {
        $student = User::factory()->create(['tenant_id' => $tenant->id]);
        VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 90, 'end_s' => 100]);
    }

    Livewire::test(LessonRewatchHeatmapWidget::class)
        ->set('record', $lesson)
        ->assertSeeInOrder(['1:30-1:40', '3 students', '0:00-0:10']);
});

it('shows the empty state for a lesson with no watch events yet', function () {
    [, $lesson] = rewatchWidgetLessonFixture();

    Livewire::test(LessonRewatchHeatmapWidget::class)
        ->set('record', $lesson)
        ->assertSee('No watch data yet');
});
