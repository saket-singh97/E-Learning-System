<?php

namespace App\Domains\Content\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BandwidthUsageDaily extends Model
{
    use BelongsToTenant;

    protected $table = 'bandwidth_usage_daily';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'date',
        'bytes_served',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public static function record(int $bytes): void
    {
        $row = static::firstOrCreate(
            ['date' => now()->toDateString()],
            ['bytes_served' => 0],
        );

        $row->increment('bytes_served', $bytes);
    }
}
