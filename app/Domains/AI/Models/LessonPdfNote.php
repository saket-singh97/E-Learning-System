<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Lesson;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonPdfNote extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'disk_path',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
