<?php

namespace App\Domains\Api\Models;

use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'url',
        'secret',
        'status',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
