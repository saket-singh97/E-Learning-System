<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\ChunkTranscript;
use App\Domains\AI\Jobs\EmbedLessonChunks;
use App\Domains\AI\Jobs\ExtractLessonConcepts;
use App\Domains\AI\Jobs\TranscribeLesson;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;

/**
 * Queued like the jobs it dispatches, so it re-binds CurrentTenant itself
 * (RebindsTenant is scoped to the four chained jobs per the S3 plan, not
 * this listener) before touching any tenant-scoped model.
 */
class StartIngestion implements ShouldQueue
{
    use Queueable;

    /**
     * lesson_artifacts has a unique(lesson_id, version) constraint (see the
     * S3 schema migration) but the version number itself is computed with a
     * plain read-then-write (max(version)+1) rather than a DB-level
     * sequence. Two LessonVideoReady events for the same lesson landing on
     * two queue workers close together (a double-submit re-upload, or a
     * queue redelivery racing an in-flight one) can both read the same max
     * and then collide on create(). Recompute the version and retry rather
     * than let the listener job fail silently with no LessonArtifact row
     * and no LessonArtifactFailed signal at all.
     */
    private const MAX_VERSION_ATTEMPTS = 3;

    public function handle(LessonVideoReady $event): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($event->tenantId));

        $lesson = Lesson::findOrFail($event->lessonId);

        $artifact = $this->createNextVersion($lesson);

        Bus::chain([
            new TranscribeLesson($artifact->id, $event->tenantId),
            new ChunkTranscript($artifact->id, $event->tenantId),
            new ExtractLessonConcepts($artifact->id, $event->tenantId),
            new EmbedLessonChunks($artifact->id, $event->tenantId),
        ])->dispatch();
    }

    private function createNextVersion(Lesson $lesson): LessonArtifact
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $nextVersion = (int) (LessonArtifact::where('lesson_id', $lesson->id)->max('version') ?? 0) + 1;

            try {
                return LessonArtifact::create([
                    'lesson_id' => $lesson->id,
                    'version' => $nextVersion,
                    'source_asset_version' => $lesson->video_disk_path,
                    'status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_VERSION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
