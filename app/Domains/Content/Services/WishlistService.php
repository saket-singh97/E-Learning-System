<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Wishlist;
use App\Models\User;

class WishlistService
{
    /**
     * Single toggle point shared by CourseCatalog and MyWishlist — both
     * need identical find-or-create/delete semantics against the same
     * (tenant_id, user_id, course_id) unique constraint.
     */
    public function toggle(User $user, Course $course): bool
    {
        $existing = Wishlist::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        Wishlist::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        return true;
    }
}
