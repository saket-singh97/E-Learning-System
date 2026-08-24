<?php

namespace App\Domains\Assessments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S16 B9 (I8 pt.1) — logged by QuizRunner's client-side listeners while a
 * proctoring-enabled quiz's attempt is in progress. Detection/evidence
 * only, not prevention (design doc §4 decision 4) — no verdict lives on
 * this row itself, I9 (B11) reads the count against a threshold.
 */
class QuizProctoringEvent extends Model
{
    use BelongsToTenant;

    public const EVENT_TYPES = ['tab_switch', 'fullscreen_exit', 'copy', 'paste'];

    protected $fillable = [
        'tenant_id',
        'quiz_attempt_id',
        'event_type',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }
}
