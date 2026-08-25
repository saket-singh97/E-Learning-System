<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Lesson;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonRevisionNote extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'source_artifact_version',
        'status',
        'notes',
        'token_cost',
    ];

    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'token_cost' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * S1 §5.1's own "consumers always read the highest version where the
     * row is actually usable" contract, mirrored here for notes:
     * status=ready, latest source_artifact_version.
     */
    public static function latestReadyFor(int $lessonId): ?self
    {
        return static::where('lesson_id', $lessonId)->where('status', 'ready')->orderByDesc('source_artifact_version')->first();
    }
}
