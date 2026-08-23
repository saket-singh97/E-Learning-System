<?php

namespace App\Domains\Operations\Models;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Genuinely new business surface (design doc §3.1) — pre-enrolment,
 * often pre-User (a prospect isn't a tenant user until they convert),
 * so this domain lands rather than forcing it into Batches or Tenancy.
 */
class Enquiry extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['new', 'contacted', 'follow_up', 'converted', 'lost'];

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'course_id',
        'status',
        'source',
        'assigned_to_user_id',
        'converted_enrolment_id',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function convertedEnrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class, 'converted_enrolment_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(EnquiryFollowUp::class)->orderByDesc('created_at');
    }
}
