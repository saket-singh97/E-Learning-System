<?php

namespace App\Domains\Content\Listeners;

use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\AudioExtractionProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * U7 pt.1 (S22 B7) — additive to LessonVideoReady, same shape
 * GenerateLessonSubtitles/StartIngestion already take on this event; a
 * pure media transform on the lesson's own video, so it doesn't wait on
 * LessonArtifactReady's AI pipeline the way U1/U6 do. Failure handling
 * mirrors LiveRecording's own pending -> (working) -> ready|failed shape.
 */
class ExtractLessonAudio implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(LessonVideoReady $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $lesson = Lesson::findOrFail($event->lessonId);

        $track = LessonAudioTrack::updateOrCreate(
            ['lesson_id' => $lesson->id],
            ['status' => 'pending', 'disk_path' => null],
        );

        try {
            $result = app(AudioExtractionProvider::class)->extract($lesson);

            $track->update(['status' => 'ready', 'disk_path' => $result['disk_path']]);
        } catch (Throwable $e) {
            $track->update(['status' => 'failed']);

            $this->fail($e);
        }
    }
}
