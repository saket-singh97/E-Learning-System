<?php

namespace App\Domains\Batches\Listeners;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Support\Caching\AggregateCache;

/**
 * S12 B12 (design doc §4 decision 11) — a third listener on the same
 * `AttendanceRecorded` event `SendAbsenceAlert`/`SendLowAttendanceAlert`
 * already react to (S8 B3), same "one listener per concern" shape used
 * throughout this app. Covers both real writers
 * (`RecordVideoCoverageAttendance`, `BatchSessionsRelationManager`'s
 * markAttendance action) for free — both already funnel through this one
 * event, no separate hook needed on either writer directly.
 *
 * Only busts `StudentMetrics`' own cache — attendance never feeds
 * `GradebookService::scoreFor()` (confirmed by reading its own two
 * private helpers, neither reads `AttendanceRecord`), so a gradebook bust
 * here would be a no-op cache clear for data that was never stale.
 */
class BustAggregateCacheOnAttendance
{
    public function __construct(private readonly AggregateCache $cache) {}

    public function handle(AttendanceRecorded $event): void
    {
        $this->cache->forgetStudentMetrics($event->tenantId, $event->userId);
        $this->cache->forgetInstituteOverview($event->tenantId);
    }
}
