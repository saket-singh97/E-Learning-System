<?php

namespace App\Domains\Content\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAudioTrack extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'disk_path',
        'status',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
