<?php

namespace App\Domains\AI\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class LessonArtifactChunk extends Model
{
    use BelongsToTenant, HasNeighbors;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'lesson_artifact_id',
        'chunk_idx',
        'start_s',
        'end_s',
        'text',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
        ];
    }

    public function lessonArtifact(): BelongsTo
    {
        return $this->belongsTo(LessonArtifact::class);
    }
}
