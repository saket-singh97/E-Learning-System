<?php

namespace App\Domains\Fees\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S20 P9 — schema lands in B3, the checkout/recurring-billing flow that
 * actually creates these rows lands in B4 (design doc §3.2).
 */
class StudentSubscription extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['trialing', 'active', 'past_due', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'student_membership_plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'razorpay_subscription_id',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudentMembershipPlan::class, 'student_membership_plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
