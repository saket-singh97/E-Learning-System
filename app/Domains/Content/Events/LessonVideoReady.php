<?php

namespace App\Domains\Content\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LessonVideoReady
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $lessonId,
        public readonly int $tenantId,
    ) {}
}
