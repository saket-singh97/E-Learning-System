<?php

namespace App\Domains\AI\Jobs\Concerns;

use App\Domains\AI\Events\LessonArtifactFailed;
use App\Domains\AI\Models\LessonArtifact;
use Throwable;

/**
 * Shared by all four ingestion jobs: mark the artifact failed, fire the
 * event, then $this->fail() so the queue records the job as failed and the
 * Bus::chain stops — CallQueuedHandler only dispatches the next chained job
 * when the current one didn't fail or release itself.
 */
trait HandlesIngestionFailure
{
    protected function failArtifact(LessonArtifact $artifact, Throwable $e): void
    {
        $artifact->update([
            'status' => 'failed',
            'failure_reason' => $e->getMessage(),
        ]);

        event(new LessonArtifactFailed($artifact->id, $artifact->tenant_id));

        $this->fail($e);
    }
}
