<?php

namespace App\Domains\Comms\Models;

use App\Domains\Tenancy\Services\RoleHierarchy;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Targets roles, not individual users (design decision 11) —
 * `audience_roles` is a JSON subset of RoleHierarchy's own 5 roles; an
 * institute-wide announcement is inherently a broadcast, not a
 * per-recipient message the way DirectMessage already is. Plain-text
 * body, same precedent DirectMessage/ForumPost already established (not
 * a rich-text field, SanitizesHtml doesn't apply). `published_at` null
 * means draft — not yet visible anywhere (B17's own display concern).
 */
class Notice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'title',
        'body',
        'audience_roles',
        'published_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'audience_roles' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function isForRole(string $role): bool
    {
        return in_array($role, $this->audience_roles ?? [], true);
    }

    /**
     * @return array<string, string>
     */
    public static function audienceRoleOptions(): array
    {
        return array_combine(array_keys(RoleHierarchy::LEVELS), array_keys(RoleHierarchy::LEVELS));
    }
}
