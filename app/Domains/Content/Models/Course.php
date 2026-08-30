<?php

namespace App\Domains\Content\Models;

use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const STATUSES = ['draft', 'published', 'archived'];

    /** @var array<int, string> S23 B7 (U17) — dual-language content tracks. */
    public const LANGUAGES = ['en', 'hi'];

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'description',
        'status',
        'gradebook_weights',
        'passing_threshold',
        'language',
        'translation_of_course_id',
    ];

    protected array $sanitizable = [
        'description',
    ];

    protected function casts(): array
    {
        return [
            'gradebook_weights' => 'array',
            'passing_threshold' => 'decimal:2',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position');
    }

    public function syllabusTopics(): HasMany
    {
        return $this->hasMany(SyllabusTopic::class)->orderBy('sequence');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'course_category');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'course_tag');
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class);
    }

    /**
     * S23 B8 (U17 pt.2) — the course this one is a translated track of, if
     * any. Two independently-authored `Course` rows, not shared content
     * (design doc §4 decision 5).
     */
    public function translationOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translation_of_course_id');
    }

    /** Reverse direction — courses that name this one as their `translation_of_course_id`. */
    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'translation_of_course_id');
    }

    /**
     * The one other course this one's language switcher points to, in
     * whichever direction the link was set (design doc §4 decision 5: "a
     * course detail page shows a switcher when translation_of_course_id is
     * set either direction"). Only two languages exist in this slice, so a
     * course has at most one live counterpart in practice.
     */
    public function translationCounterpart(): ?self
    {
        return $this->translationOf ?? $this->translations()->first();
    }
}
