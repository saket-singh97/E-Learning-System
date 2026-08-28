<?php

namespace App\Domains\Api\Models;

use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'webhook_endpoint_id',
        'event_type',
        'payload',
        'status',
        'attempt_count',
        'last_attempted_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }
}
