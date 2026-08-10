<?php

namespace App\Domains\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LessonArtifactFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $lessonArtifactId,
        public readonly int $tenantId,
    ) {}
}
