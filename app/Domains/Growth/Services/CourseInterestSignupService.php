<?php

namespace App\Domains\Growth\Services;

use App\Domains\Content\Models\Course;
use App\Domains\Growth\Models\CourseInterestSignup;

/**
 * The single write chokepoint for a course-interest signup — a repeat
 * signup with the same email for the same course updates the existing
 * row (name/phone may have changed) rather than creating a duplicate,
 * enforced at the DB level by the `(course_id, email)` unique index.
 */
class CourseInterestSignupService
{
    public function signup(Course $course, string $name, string $email, ?string $phone): CourseInterestSignup
    {
        $signup = CourseInterestSignup::firstOrNew([
            'course_id' => $course->id,
            'email' => $email,
        ]);

        $signup->fill([
            'name' => $name,
            'phone' => $phone,
        ])->save();

        return $signup;
    }
}
