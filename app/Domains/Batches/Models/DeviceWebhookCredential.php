<?php

namespace App\Domains\Batches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S16 B4 (H12) — a generic, vendor-agnostic biometric/RFID device webhook
 * credential. `batch_id` null means "any batch" (design doc §3.2) — the
 * device covers the whole institute rather than one classroom door.
 */
class DeviceWebhookCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'device_label',
        'webhook_secret',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
