<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Lesson;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonArtifact extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'processing', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'version',
        'source_asset_version',
        'status',
        'transcript',
        'chunks',
        'concepts',
        'syllabus_map',
        'difficulty',
        'seed_questions',
        'model_route',
        'token_cost',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'chunks' => 'array',
            'concepts' => 'array',
            'syllabus_map' => 'array',
            'difficulty' => 'array',
            'seed_questions' => 'array',
            'model_route' => 'array',
            'token_cost' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function chunkRows(): HasMany
    {
        return $this->hasMany(LessonArtifactChunk::class);
    }

    /**
     * Most recent ingestion attempt for a lesson, any status — what a status
     * badge shows. Not what a content reader should use (see below).
     */
    public static function latestFor(int $lessonId): ?self
    {
        return static::where('lesson_id', $lessonId)->orderByDesc('version')->first();
    }

    /**
     * S1 §5.1 contract rule 1: consumers always read the highest version
     * where status = ready. A pending/processing/failed row is never valid
     * to read content from, even if it's the newest row for the lesson.
     */
    public static function latestReadyFor(int $lessonId): ?self
    {
        return static::where('lesson_id', $lessonId)->where('status', 'ready')->orderByDesc('version')->first();
    }
}
