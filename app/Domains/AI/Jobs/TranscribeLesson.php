<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Jobs\Concerns\HandlesIngestionFailure;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Content\Models\Lesson;
use App\Integrations\AiProvider;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TranscribeLesson implements ShouldQueue
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
            $artifact->update(['status' => 'processing']);

            $lesson = Lesson::findOrFail($artifact->lesson_id);
            $result = $ai->transcribe($lesson->video_disk_path);

            $chunks = [];
            foreach ($result->segments as $idx => $segment) {
                $chunks[] = ['idx' => $idx, ...$segment];
            }

            $artifact->update([
                'transcript' => $result->text,
                'chunks' => $chunks,
            ]);
        } catch (Throwable $e) {
            $this->failArtifact($artifact, $e);
        }
    }
}
