<?php

namespace App\Domains\Growth\Models;

use App\Domains\Content\Models\Course;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S20 R6 — a prospect isn't a user yet, so this takes a bare
 * name/email/phone, no user_id (design doc §4 decision 8) — closer to
 * `ParentInviteRedemption`'s "an outside party with no account yet" shape
 * than any of this app's other guest-facing forms.
 */
class CourseInterestSignup extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'course_id',
        'name',
        'email',
        'phone',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
