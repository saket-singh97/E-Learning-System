<?php

namespace App\Domains\Assessments\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['in_progress', 'submitted', 'auto_submitted'];

    protected $fillable = [
        'tenant_id',
        'quiz_id',
        'user_id',
        'question_ids',
        'status',
        'started_at',
        'expires_at',
        'submitted_at',
        'score',
        'max_score',
    ];

    protected function casts(): array
    {
        return [
            'question_ids' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
