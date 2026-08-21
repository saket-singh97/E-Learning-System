<?php

namespace App\Domains\Batches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivePollOption extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'live_poll_id',
        'label',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LivePoll::class, 'live_poll_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LivePollResponse::class);
    }
}
