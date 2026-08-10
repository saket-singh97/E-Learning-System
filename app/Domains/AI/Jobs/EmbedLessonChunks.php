<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Events\LessonArtifactReady;
use App\Domains\AI\Jobs\Concerns\HandlesIngestionFailure;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Integrations\AiProvider;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EmbedLessonChunks implements ShouldQueue
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

    public function handle(AiProvider $ai): void
    {
        $artifact = LessonArtifact::findOrFail($this->lessonArtifactId);

        try {
            $chunks = LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)->get();

            foreach ($chunks as $chunk) {
                $chunk->update(['embedding' => $ai->embed($chunk->text)]);
            }

            $artifact->update(['status' => 'ready']);

            event(new LessonArtifactReady($artifact->id, $artifact->tenant_id));
        } catch (Throwable $e) {
            $this->failArtifact($artifact, $e);
        }
    }
}
