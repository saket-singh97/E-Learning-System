<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Same three gates and same signed-URL posture as StreamLessonController's
 * own video bytes (S9 B6 plan note: "same disk, same signed-URL pattern
 * video already uses") — a PDF isn't scrubbed/seeked the way a <video>
 * element is, so there's no Range-request partial-byte accounting to
 * replicate, just the whole file's size metered once per request.
 */
class DownloadLessonPdfController extends Controller
{
    /**
     * Lesson id read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController (CLAUDE.md).
     */
    public function __invoke(Request $request, LessonAccess $access): BinaryFileResponse
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));

        $viewer = $request->user();

        abort_unless($access->isPlayable($lesson) && $access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);
        abort_unless($lesson->type === 'pdf', 404);

        $path = Storage::disk('local')->path($lesson->pdf_disk_path);
        $bytes = (int) filesize($path);

        $access->assertWithinBandwidthQuota($lesson, $bytes);

        BandwidthUsageDaily::record($bytes);

        return response()->file($path);
    }
}
