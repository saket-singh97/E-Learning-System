<?php

namespace App\Domains\AI\Jobs;

use App\Domains\AI\Jobs\Concerns\HandlesIngestionFailure;
use App\Domains\AI\Models\LessonArtifact;
use App\Integrations\AiProvider;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractLessonConcepts implements ShouldQueue
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
            $result = $ai->chat(
                messages: [['role' => 'user', 'content' => "Extract exam concepts from this lecture transcript:\n{$artifact->transcript}"]],
                responseSchema: ['concepts', 'syllabus_map', 'difficulty', 'seed_questions'],
            );

            $modelId = class_basename($ai);

            $artifact->update([
                'concepts' => $result->structured['concepts'],
                'syllabus_map' => $result->structured['syllabus_map'],
                'difficulty' => $result->structured['difficulty'],
                'seed_questions' => $result->structured['seed_questions'],
                'model_route' => [
                    'transcribe' => $modelId,
                    'chat' => $modelId,
                    'embed' => $modelId,
                ],
                'token_cost' => $result->usage,
            ]);
        } catch (Throwable $e) {
            $this->failArtifact($artifact, $e);
        }
    }
}
