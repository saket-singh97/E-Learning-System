<?php

namespace App\Domains\Growth\Services;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Growth\Models\CourseInterestSignup;

/**
 * S23 B11 (U13 pt.2, design doc §4 decision 3) — a query, not an event: "did
 * this prospect convert" is answered on read, matched against `Enrolment`
 * via the enrolled user's own email, scoped to the same tenant/course. A
 * prospect can convert weeks later through any of this app's several
 * enrolment paths (checkout, admissions-CRM, bulk CSV import) — wiring a
 * listener onto all of them for one derived boolean would be the "three
 * copies of a check" S20's own deferred-debt list already warns against.
 */
class DemoConversionService
{
    public function hasConverted(CourseInterestSignup $signup): bool
    {
        return Enrolment::query()
            ->where('tenant_id', $signup->tenant_id)
            ->whereHas('user', fn ($query) => $query->where('email', $signup->email))
            ->whereHas('batch', fn ($query) => $query->where('course_id', $signup->course_id))
            ->exists();
    }
}
