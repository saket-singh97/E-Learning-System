<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Services\SyllabusTracker;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S23 B1 — placed on EditCourse's getFooterWidgets(), same
 * InteractsWithRecord::getWidgetData() wiring BatchEngagementWidget/
 * BatchWeakTopicsWidget already use (S17 B9/S18 B1). Aggregate only, per
 * course — SyllabusTracker::percentCompleteFor() is per-viewer, so this
 * widget averages it across the course's actively enrolled students rather
 * than adding a second service method for a course-wide number.
 */
class CourseSyllabusTrackerWidget extends StatsOverviewWidget
{
    public ?Course $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $studentIds = Enrolment::whereIn('batch_id', Batch::where('course_id', $this->record->id)->pluck('id'))
            ->where('status', 'active')
            ->distinct()
            ->pluck('user_id');

        if ($studentIds->isEmpty()) {
            return [Stat::make('Syllabus coverage', 'No students enrolled yet')];
        }

        $tracker = app(SyllabusTracker::class);
        $perStudent = User::whereIn('id', $studentIds)->get()
            ->map(fn (User $student): array => $tracker->percentCompleteFor($this->record, $student));

        $groups = $perStudent->flatMap(fn (array $percents): array => array_keys($percents))->unique();

        if ($groups->isEmpty()) {
            return [Stat::make('Syllabus coverage', 'Syllabus not mapped yet')];
        }

        return $groups
            ->map(fn (string $group): Stat => Stat::make(
                $group,
                number_format($perStudent->avg(fn (array $percents): float => $percents[$group] ?? 0.0), 1).'% complete',
            ))
            ->values()
            ->all();
    }
}
