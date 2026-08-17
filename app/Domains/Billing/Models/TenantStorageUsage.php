<?php

namespace App\Domains\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per tenant, a running counter — not a daily log like
 * BandwidthUsageDaily. Incremented/decremented at each file-store/delete
 * call site enumerated in B4; storage is a running total, not a daily
 * flow, so there is nothing to bucket by date.
 */
class TenantStorageUsage extends Model
{
    use BelongsToTenant;

    // "usage" pluralizes to "usages" by Eloquent's default convention;
    // the schema (design doc §3.1) uses the singular table name.
    protected $table = 'tenant_storage_usage';

    protected $fillable = [
        'tenant_id',
        'bytes_used',
    ];

    protected function casts(): array
    {
        return [
            'bytes_used' => 'integer',
        ];
    }
}
