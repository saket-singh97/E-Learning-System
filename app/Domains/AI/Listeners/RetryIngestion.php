<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\ChunkTranscript;
use App\Domains\AI\Jobs\EmbedLessonChunks;
use App\Domains\AI\Jobs\ExtractLessonConcepts;
use App\Domains\AI\Jobs\TranscribeLesson;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Events\LessonIngestionRetryRequested;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

/**
 * Same tenant-rebind approach as StartIngestion — queued listeners have no
 * job-middleware hook, so this binds CurrentTenant itself before touching
 * any tenant-scoped model.
 */
class RetryIngestion implements ShouldQueue
{
    use Queueable;

    public function handle(LessonIngestionRetryRequested $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $artifact = LessonArtifact::where('lesson_id', $event->lessonId)
            ->where('status', 'failed')
            ->orderByDesc('version')
            ->first();

        if (! $artifact) {
            return;
        }

        $artifact->update(['status' => 'pending', 'failure_reason' => null]);

        Bus::chain([
            new TranscribeLesson($artifact->id, $event->tenantId),
            new ChunkTranscript($artifact->id, $event->tenantId),
            new ExtractLessonConcepts($artifact->id, $event->tenantId),
            new EmbedLessonChunks($artifact->id, $event->tenantId),
        ])->dispatch();
    }
}
