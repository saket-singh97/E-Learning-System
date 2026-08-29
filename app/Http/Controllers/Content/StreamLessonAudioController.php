<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Content\Services\ConcurrentStreamGuard;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * U7 pt.2 (S22 B8) — the lesson's audio-only track, through the exact same
 * gate/metering chokepoint StreamLessonController already is (design doc
 * B8 checklist), so audio never becomes a second, weaker-gated way to reach
 * a lesson's content. Meters the audio file's own (much smaller) byte
 * count, not the video's — the whole point of this mode.
 */
class StreamLessonAudioController extends Controller
{
    public function __invoke(Request $request, LessonAccess $access, ConcurrentStreamGuard $streamGuard): BinaryFileResponse
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));
        $viewer = $request->user();

        abort_unless($access->isPlayable($lesson) && $access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);

        $track = LessonAudioTrack::where('lesson_id', $lesson->id)->where('status', 'ready')->first();
        abort_if($track === null, 404);

        if ($viewer !== null) {
            $streamGuard->assertNoOtherActiveStream($viewer, $lesson);
        }

        $path = Storage::disk('local')->path($track->disk_path);
        $bytes = StreamLessonController::bytesServed($request, (int) filesize($path));

        $access->assertWithinBandwidthQuota($lesson, $bytes);

        BandwidthUsageDaily::record($bytes);

        return response()->file($path);
    }
}
