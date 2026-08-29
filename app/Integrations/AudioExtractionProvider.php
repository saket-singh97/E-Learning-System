<?php

namespace App\Integrations;

use App\Domains\Content\Models\Lesson;

interface AudioExtractionProvider
{
    /**
     * Extract an audio-only track from the lesson's own video and store it.
     *
     * @return array{disk_path: string}
     */
    public function extract(Lesson $lesson): array;
}
