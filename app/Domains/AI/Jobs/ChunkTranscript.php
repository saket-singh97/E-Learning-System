<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Jobs\Concerns\HandlesIngestionFailure;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ChunkTranscript implements ShouldQueue
{
    use Dispatchable, HandlesIngestionFailure, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $lessonArtifactId,
        public readonly ?int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public function handle(): void
    {
        $artifact = LessonArtifact::findOrFail($this->lessonArtifactId);

        try {
            // Idempotent: a retry re-runs the whole chain from TranscribeLesson,
            // so a prior attempt's chunk rows (from before a later stage failed)
            // must not survive alongside a fresh set, or embedding/doubt-chat
            // retrieval would see duplicates for the same lesson_artifact_id.
            LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->delete();

            foreach ($artifact->chunks ?? [] as $chunk) {
                LessonArtifactChunk::create([
                    'lesson_artifact_id' => $artifact->id,
                    'chunk_idx' => $chunk['idx'],
                    'start_s' => $chunk['start_s'],
                    'end_s' => $chunk['end_s'],
                    'text' => $chunk['text'],
                ]);
            }
        } catch (Throwable $e) {
            $this->failArtifact($artifact, $e);
        }
    }
}
