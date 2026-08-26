<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S19 N15 pt.2 — `unique(survey_id, respondent_id)`: one response per
 * respondent (design doc §3.2). `SurveyRunner::submit()` uses
 * `updateOrCreate` on that pair, same "re-submitting updates the existing
 * row rather than erroring" precedent `BatchFeedback` already established.
 */
class SurveyResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'survey_id',
        'respondent_id',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_id');
    }

    /** @return HasMany<SurveyAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }
}
