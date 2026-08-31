<?php

namespace App\Domains\Growth\Services;

use App\Domains\Growth\Models\ScholarshipTest;
use App\Domains\Growth\Models\ScholarshipTestRegistration;

/**
 * The single write chokepoint for a scholarship-test registration. A
 * repeat submission under an already-registered email is deduplicated
 * (enforced at the DB level by the (scholarship_test_id, email) unique
 * index) but, unlike `CourseInterestSignupService`'s own idiom, does NOT
 * overwrite the existing row's name/phone — S23 B20 review-pass fix:
 * unlike a plain interest signup, this row backs a printed hall ticket
 * (name + QR-coded roll number), so blindly trusting a second unverified
 * submission under a known/guessed email would let anyone deface another
 * registrant's own hall ticket by "correcting" their name. There's no
 * auth/OTP for a prospect (design §4 decision 2) to tell a genuine
 * correction apart from an attacker, so the first submission simply wins.
 */
class ScholarshipTestRegistrationService
{
    public function register(ScholarshipTest $test, string $name, string $email, ?string $phone): ScholarshipTestRegistration
    {
        $registration = ScholarshipTestRegistration::firstOrNew([
            'scholarship_test_id' => $test->id,
            'email' => $email,
        ]);

        if (! $registration->exists) {
            $registration->fill([
                'name' => $name,
                'phone' => $phone,
            ]);
        }

        $registration->save();

        return $registration;
    }
}
