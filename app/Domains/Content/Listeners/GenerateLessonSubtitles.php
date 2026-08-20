<?php

namespace App\Domains\Content\Listeners;

use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\SubtitleGenerator;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Queued like StartIngestion, so it re-binds CurrentTenant itself before
 * touching any tenant-scoped model — fires on the same LessonArtifactReady
 * event StartIngestion's own chain already produces (design doc decision 8:
 * no separate manual trigger, reuses the artifact the pipeline just built).
 */
class GenerateLessonSubtitles implements ShouldQueue
{
    use Queueable;

    public function handle(LessonArtifactReady $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $artifact = LessonArtifact::findOrFail($event->lessonArtifactId);
        $lesson = Lesson::findOrFail($artifact->lesson_id);

        $lesson->update(['subtitle_status' => 'generating']);

        try {
            $vtt = app(SubtitleGenerator::class)->toVtt($artifact);
            $diskPath = "subtitles/{$lesson->tenant_id}/{$lesson->id}.vtt";

            Storage::disk('local')->put($diskPath, $vtt);

            $lesson->update([
                'subtitle_disk_path' => $diskPath,
                'subtitle_status' => 'ready',
            ]);
        } catch (Throwable $e) {
            $lesson->update(['subtitle_status' => 'failed']);

            $this->fail($e);
        }
    }
}
