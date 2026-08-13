<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use App\Integrations\VideoProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamLessonController extends Controller
{
    /**
     * The lesson id is read off the route rather than method-injected: this
     * route lives under Route::domain('{subdomain}.lms.test'), and Laravel
     * splices leftover route parameters into method arguments by position, so
     * a scalar $lesson parameter receives the subdomain instead.
     */
    public function __invoke(Request $request, VideoProvider $video, LessonAccess $access): BinaryFileResponse
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));

        $viewer = $request->user();

        abort_unless($access->isPlayable($lesson) && $access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);

        $path = $video->url($lesson);
        $bytes = $this->bytesServed($request, (int) filesize($path));

        $access->assertWithinBandwidthQuota($lesson, $bytes);

        BandwidthUsageDaily::record($bytes);

        return response()->file($path);
    }

    /**
     * A video element seeking through a lesson issues Range requests, and each
     * one is a separate hit on this controller. Charging the tenant the whole
     * file every time turned one watch into many full-file charges, so meter
     * the slice actually sent instead.
     */
    protected function bytesServed(Request $request, int $fileSize): int
    {
        $range = (string) $request->header('Range');

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            return $fileSize;
        }

        [$from, $to] = [$matches[1], $matches[2]];

        if ($from === '') {
            // Suffix form, "bytes=-500": the last N bytes of the file.
            return $to === '' ? $fileSize : min((int) $to, $fileSize);
        }

        $start = (int) $from;
        $end = $to === '' ? $fileSize - 1 : min((int) $to, $fileSize - 1);

        return max(0, $end - $start + 1);
    }
}
