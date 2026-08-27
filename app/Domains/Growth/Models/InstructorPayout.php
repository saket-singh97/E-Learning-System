<?php

namespace App\Domains\Growth\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S20 R3 — Growth's own domain, same reasoning `AffiliateCode` already
 * gives: money flowing to a third party (the instructor) who is neither
 * the paying student nor the platform. Computes an amount; never moves
 * money itself (design doc §4 decision 7) — `markPaid` records that a
 * transfer happened outside this app, it doesn't trigger one.
 */
class InstructorPayout extends Model
{
    use BelongsToTenant;

    public const BASIS_SALARIED = 'salaried';

    public const BASIS_REVENUE_SHARE = 'revenue_share';

    public const BASES = [self::BASIS_SALARIED, self::BASIS_REVENUE_SHARE];

    public const STATUSES = ['pending', 'approved', 'paid', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'instructor_id',
        'period_start',
        'period_end',
        'basis',
        'amount',
        'status',
        'approved_by',
        'approved_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
