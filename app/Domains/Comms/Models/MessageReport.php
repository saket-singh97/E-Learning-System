<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReport extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['open', 'actioned', 'dismissed'];

    protected $fillable = [
        'tenant_id',
        'reporter_id',
        'direct_message_id',
        'reason',
        'status',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class, 'direct_message_id');
    }
}
