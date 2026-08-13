<?php

namespace App\Domains\Assessments\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    use BelongsToTenant;

    /**
     * 'draft' (S9 B7) is an autosaved-but-ungraded answer, written while
     * the attempt is still in_progress — never surfaced in
     * QuizAnswerResource's review queue, which excludes it explicitly.
     */
    public const STATUSES = ['draft', 'auto_graded', 'pending_review', 'reviewed'];

    protected $fillable = [
        'tenant_id',
        'quiz_attempt_id',
        'question_id',
        'response',
        'is_correct',
        'score',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'is_correct' => 'boolean',
            'score' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
