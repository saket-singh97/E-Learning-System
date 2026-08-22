<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batches domain, not Comms — same "entity-owning domain" precedent
 * CourseReview set for course-scoped feedback in S13 (design doc §3.1).
 * Plain-text comment, same as CourseReview's own body (not a rich-text
 * field, SanitizesHtml doesn't apply).
 */
class BatchFeedback extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
