<?php

namespace App\Domains\Growth\Services;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Growth\Models\ScholarshipTestRegistration;

/**
 * S23 B14 (U12 pt.3, design doc §4 decision 3's same posture) — a query,
 * not an event, mirroring DemoConversionService exactly. Scoped to the
 * test's course only when one is set (ScholarshipTest.course_id is
 * nullable — a test can be tenant-wide, not tied to a catalog page).
 */
class ScholarshipConversionService
{
    public function hasConverted(ScholarshipTestRegistration $registration): bool
    {
        $courseId = $registration->scholarshipTest->course_id;

        return Enrolment::query()
            ->where('tenant_id', $registration->tenant_id)
            ->whereHas('user', fn ($query) => $query->where('email', $registration->email))
            ->when(
                $courseId !== null,
                fn ($query) => $query->whereHas('batch', fn ($q) => $q->where('course_id', $courseId)),
            )
            ->exists();
    }
}
