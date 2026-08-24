<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S16 B6 (H13 pt.2) — created by `TeacherAttendanceSweep` (B5) once per
 * detected gap; no automatic substitute matching (design doc §4 decision
 * 10) — resolved manually via `TeacherAttendanceFlagResource`.
 */
class TeacherAttendanceFlag extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['open', 'resolved'];

    protected $fillable = [
        'tenant_id',
        'batch_session_id',
        'instructor_id',
        'flagged_at',
        'status',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
        ];
    }

    public function batchSession(): BelongsTo
    {
        return $this->belongsTo(BatchSession::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
