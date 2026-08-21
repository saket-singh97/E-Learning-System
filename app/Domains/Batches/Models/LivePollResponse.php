<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's vote (design doc §3.1, decision 11) — unique on
 * (live_poll_id, user_id) at the DB level, same defense-in-depth precedent
 * S13's own LessonQuestionVote already established.
 */
class LivePollResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'live_poll_id',
        'live_poll_option_id',
        'user_id',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LivePoll::class, 'live_poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(LivePollOption::class, 'live_poll_option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
