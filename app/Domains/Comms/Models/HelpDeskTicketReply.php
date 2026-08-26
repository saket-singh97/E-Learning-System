<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S19 N16 — `is_internal_note` (default false) marks a staff-only reply the
 * requester never sees, same "flag, don't build a second table" shape this
 * app already prefers for a binary visibility distinction.
 */
class HelpDeskTicketReply extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'author_id',
        'body',
        'is_internal_note',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpDeskTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
