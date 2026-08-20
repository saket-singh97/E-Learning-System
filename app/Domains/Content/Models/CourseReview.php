<?php

namespace App\Domains\Content\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plain-text review/response bodies, escaped at render time via Blade's
 * default `{{ }}` — same precedent LessonQuestion's own body already
 * established (not a rich-text/WYSIWYG field, so SanitizesHtml doesn't
 * apply the way it does to Course.description/Lesson.text_body).
 */
class CourseReview extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['published', 'hidden'];

    protected $fillable = [
        'tenant_id',
        'course_id',
        'user_id',
        'rating',
        'body',
        'instructor_response',
        'instructor_response_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'instructor_response_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
