<?php

namespace App\Domains\Batches\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per captured recording (design doc §3.1, G5/G6) — created
 * `pending` by ZoomRecordingWebhookHandler once Zoom's own
 * recording.completed webhook fires (B8); DownloadLiveRecording (B9)
 * carries it through downloading -> ready|failed.
 */
class LiveRecording extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'downloading', 'ready', 'failed'];

    protected $fillable = [
        'tenant_id',
        'batch_session_id',
        'provider_recording_id',
        'disk_path',
        'status',
    ];

    public function batchSession(): BelongsTo
    {
        return $this->belongsTo(BatchSession::class);
    }
}
