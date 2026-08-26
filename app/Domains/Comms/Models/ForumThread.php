<?php

namespace App\Domains\Comms\Models;

use App\Domains\Batches\Models\Batch;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch-scoped (F6, design doc §3.1) — adjacent to but distinct from
 * DirectMessage/MessageReport's own private-messaging shape; a thread is
 * visible to everyone actively enrolled in its own batch, not one-to-one.
 *
 * `batch_id` is nullable since S19 N14 (design doc §3.2/§4 decision 5): a
 * null value means a general, institute-wide thread instead (GeneralForum),
 * visible to every tenant user rather than one batch's enrolled roster.
 */
class ForumThread extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'title',
        'created_by_user_id',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class)->orderBy('created_at');
    }
}
