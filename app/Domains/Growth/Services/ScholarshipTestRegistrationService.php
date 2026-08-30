<?php

namespace App\Domains\Growth\Services;

use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;

/**
 * The single write chokepoint for a scholarship-test registration — same
 * idiom as CourseInterestSignupService: a repeat signup with the same email
 * for the same test updates the existing row (name/phone may have changed)
 * rather than creating a duplicate, enforced at the DB level by the
 * (scholarship_test_id, email) unique index.
 */
class ScholarshipTestRegistrationService
{
    public function register(ScholarshipTest $test, string $name, string $email, ?string $phone): ScholarshipTestRegistration
    {
        $registration = ScholarshipTestRegistration::firstOrNew([
            'scholarship_test_id' => $test->id,
            'email' => $email,
        ]);

        $registration->fill([
            'name' => $name,
            'phone' => $phone,
        ])->save();

        return $registration;
    }
}
