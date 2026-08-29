<?php

namespace App\Integrations\Drivers;

use App\Domains\Content\Models\Lesson;
use App\Integrations\AudioExtractionProvider;
use Illuminate\Support\Facades\Storage;

/**
 * U7 (S22 B7) — same "Fake now, real driver once a vendor/tool is actually
 * chosen" shape AiProvider/VideoProvider shipped in S2/S3 before a real
 * vendor existed (design doc §2.4). A real ffmpeg/Bunny-audio driver is a
 * deliberate fast-follow, not blocking this slice.
 */
class FakeAudioExtractionDriver implements AudioExtractionProvider
{
    public function extract(Lesson $lesson): array
    {
        $diskPath = "lesson-audio/{$lesson->tenant_id}/{$lesson->id}.mp3";

        Storage::disk('local')->put($diskPath, 'fake-audio-bytes');

        return ['disk_path' => $diskPath];
    }
}
