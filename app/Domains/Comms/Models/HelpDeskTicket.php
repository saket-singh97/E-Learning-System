<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S19 N16 — `sla_due_at` is computed once at creation
 * (`created_at + config('comms.help_desk_sla_hours')[priority]`, design
 * doc §4 decision 7), not recalculated live.
 */
class HelpDeskTicket extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'tenant_id',
        'requester_id',
        'subject',
        'body',
        'status',
        'priority',
        'assigned_to_user_id',
        'sla_due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<HelpDeskTicketReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(HelpDeskTicketReply::class, 'ticket_id')->orderBy('created_at');
    }
}
