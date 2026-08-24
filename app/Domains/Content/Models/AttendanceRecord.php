<?php

namespace App\Domains\Content\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['present', 'absent'];

    /**
     * @var array<int, string> Design doc §3.1 — two writers ship in S5; a
     * third (live-webhook) is fast-follow. S16 B3 adds `geofence_checkin`
     * (H11); `device_webhook` (H12) lands in a later block.
     */
    public const SOURCES = ['manual', 'video_coverage', 'geofence_checkin'];

    /** @var array<int, string> S16 B0 — distinguishes a teacher's own attendance row from a student's, same table. */
    public const ROLES = ['student', 'instructor'];

    protected $fillable = [
        'tenant_id',
        'attendable_type',
        'attendable_id',
        'user_id',
        'role',
        'status',
        'source',
        'marked_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }
}
