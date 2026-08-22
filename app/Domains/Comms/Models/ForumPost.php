<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `depth` (0-2) is a stored column, not computed by walking `parent_id` on
 * every read (design decision 5) — write-time refusal at depth 3 lives in
 * ForumPostComposer, the single write path for this table (same
 * single-chokepoint posture PrerequisiteGraph::addPrerequisite() already
 * established for a different bounded-depth problem).
 */
class ForumPost extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'forum_thread_id',
        'parent_id',
        'depth',
        'author_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'forum_thread_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
