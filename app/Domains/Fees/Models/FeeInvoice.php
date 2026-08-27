<?php

namespace App\Domains\Fees\Models;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Program;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * S12 B13 (design doc §3.2, V3) — SoftDeletes added, same reasoning
 * FeePayment's own docblock gives: a real invoice hard-deleted by a
 * mis-click had no recovery path before this block.
 */
class FeeInvoice extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    /** @var array<string, string> ISO 4217 code => display symbol, falls back to "{code} " for anything not listed. */
    public const CURRENCY_SYMBOLS = [
        'INR' => '₹',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    // Matches the DB column default — same reason Tenant's own
    // `gamification_leaderboard_enabled` needs this: Eloquent doesn't
    // re-fetch a server-side default after create(), so a freshly-created
    // (not re-queried) instance would otherwise read `currency` as null.
    protected $attributes = [
        'currency' => 'INR',
    ];

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'enrolment_id',
        'program_id',
        'student_membership_plan_id',
        'user_id',
        'coupon_id',
        'sequence_number',
        'invoice_number',
        'description',
        'amount',
        'gst_rate',
        'gst_amount',
        'total_amount',
        'currency',
        'discount_amount',
        'due_date',
        'status',
        'gstin_snapshot',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(StudentMembershipPlan::class, 'student_membership_plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(FeeCoupon::class, 'coupon_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function currencySymbol(): string
    {
        return self::CURRENCY_SYMBOLS[$this->currency] ?? "{$this->currency} ";
    }
}
