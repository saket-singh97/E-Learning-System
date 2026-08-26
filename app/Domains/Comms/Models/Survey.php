<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S19 N15 — a lighter, parallel shape to Question/Quiz, not a reuse of the
 * Assessments tables (design doc §4 decision 6): a response isn't graded,
 * has no `correct_answer`, and needs an open/closed lifecycle a quiz
 * attempt doesn't have.
 */
class Survey extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'published', 'closed'];

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'status',
        'created_by_user_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<SurveyQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
