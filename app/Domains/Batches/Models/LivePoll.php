<?php

namespace App\Domains\Batches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One poll asked during a live BatchSession (design doc §3.1, G9) — created
 * draft, opened by the instructor for students to respond to, closed to
 * freeze the aggregate. Schema only in this block (B13); launch/respond/
 * aggregate logic lands in B14-B16.
 */
class LivePoll extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'open', 'closed'];

    protected $fillable = [
        'tenant_id',
        'batch_session_id',
        'question',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function batchSession(): BelongsTo
    {
        return $this->belongsTo(BatchSession::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(LivePollOption::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LivePollResponse::class);
    }
}
