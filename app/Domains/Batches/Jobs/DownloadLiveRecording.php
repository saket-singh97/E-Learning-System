<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Models\InstituteZoomConnection;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * G5 pt.2 (design doc decision 4) — downloads a Zoom cloud recording onto
 * the lesson's own `video_disk_path` (no new playback column — reuses the
 * exact path a manually-uploaded recording already uses) and fires the
 * *existing* LessonVideoReady event so S3's ingestion pipeline picks it up
 * completely unchanged. Idempotent: dispatched only for a 'pending'
 * LiveRecording (ZoomRecordingWebhookHandler's own check before
 * dispatching), and a retried run against an already-'ready' row here is
 * a no-op too — matches PaymentReconciler's own established
 * idempotent-webhook discipline.
 */
class DownloadLiveRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $liveRecordingId,
        public readonly int $tenantId,
    ) {}

    public function handle(): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($this->tenantId));

        $recording = LiveRecording::find($this->liveRecordingId);

        if ($recording === null || $recording->status === 'ready') {
            return;
        }

        $recording->update(['status' => 'downloading']);

        try {
            $session = $recording->batchSession;
            $lesson = $session->lesson;
            $connection = InstituteZoomConnection::where('tenant_id', $this->tenantId)->firstOrFail();

            $files = Http::withToken($connection->access_token)
                ->get("https://api.zoom.us/v2/meetings/{$session->provider_meeting_ref}/recordings")
                ->throw()
                ->json('recording_files', []);

            $downloadUrl = collect($files)->firstWhere('file_type', 'MP4')['download_url'] ?? null;

            if ($downloadUrl === null) {
                throw new RuntimeException("No MP4 recording file found for meeting [{$session->provider_meeting_ref}].");
            }

            $bytes = Http::withToken($connection->access_token)->get($downloadUrl)->throw()->body();

            $diskPath = "videos/{$this->tenantId}/{$lesson->id}.mp4";
            Storage::disk('local')->put($diskPath, $bytes);

            $lesson->update(['video_disk_path' => $diskPath, 'video_status' => 'ready']);

            $recording->update(['disk_path' => $diskPath, 'status' => 'ready']);

            event(new LessonVideoReady($lesson->id, $this->tenantId));
        } catch (Throwable $e) {
            $recording->update(['status' => 'failed']);

            $this->fail($e);
        }
    }
}
