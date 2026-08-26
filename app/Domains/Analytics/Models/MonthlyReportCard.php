<?php

namespace App\Domains\Analytics\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A5 (design doc §3.2) — one row per student per calendar month, tracking
 * generation/delivery status only; the actual attendance/quiz/engagement
 * numbers are computed on demand by ReportCardDataAggregator, never stored
 * here, since `status`/`pdf_disk_path`/`sent_at` is everything the
 * idempotent generate-then-send pipeline (B9/B10) needs to track.
 */
class MonthlyReportCard extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'generated', 'sent', 'failed'];

    protected $fillable = [
        'tenant_id',
        'student_id',
        'period_month',
        'status',
        'pdf_disk_path',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
