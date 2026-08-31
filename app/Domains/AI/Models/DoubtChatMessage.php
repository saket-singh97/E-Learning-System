<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Lesson;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoubtChatMessage extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'lesson_id',
        'user_id',
        'question',
        'answer',
        'cited_chunk_idxs',
        'token_cost',
        'channel',
        'source_phone',
    ];

    protected function casts(): array
    {
        return [
            'cited_chunk_idxs' => 'array',
            'token_cost' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
