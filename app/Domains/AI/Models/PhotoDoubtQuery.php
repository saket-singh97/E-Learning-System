<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoDoubtQuery extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'processing', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'course_id',
        'image_disk_path',
        'status',
        'extracted_question',
        'solution',
        'token_cost',
    ];

    protected function casts(): array
    {
        return [
            'token_cost' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
