<?php

namespace App\Domains\Fees\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S20 P9 — an institute's own recurring membership catalog, parallel to
 * (not a replacement for) the instalment-plan `FeeInvoice` flow: open-ended
 * and recurring rather than fixed-schedule/fixed-total, and not tied to one
 * course/batch enrolment (design doc §4 decision 3).
 */
class StudentMembershipPlan extends Model
{
    use BelongsToTenant;

    public const BILLING_INTERVALS = ['monthly', 'yearly'];

    public const STATUSES = ['draft', 'active', 'archived'];

    protected $fillable = [
        'tenant_id',
        'name',
        'price',
        'billing_interval',
        'trial_days',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_days' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(StudentSubscription::class);
    }
}
