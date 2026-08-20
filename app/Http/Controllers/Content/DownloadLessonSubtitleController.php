<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Same signed-URL, same-three-gates shape as DownloadLessonPdfController —
 * a subtitle track is served from the exact `<track>` src LessonPlayer
 * hands the browser (design doc D13 pt.2). No bandwidth-quota accounting
 * here: a VTT cue file is a few KB of text, not the metered video/pdf
 * bytes BandwidthQuota exists to cap.
 */
class DownloadLessonSubtitleController extends Controller
{
    /**
     * Lesson id read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController (CLAUDE.md).
     */
    public function __invoke(Request $request, LessonAccess $access): Response
    {
        $lesson = Lesson::findOrFail($request->route('lesson'));

        $viewer = $request->user();

        abort_unless($access->isPlayable($lesson) && $access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);
        abort_unless($lesson->subtitle_status === 'ready' && filled($lesson->subtitle_disk_path), 404);

        return response(Storage::disk('local')->get($lesson->subtitle_disk_path), 200, [
            'Content-Type' => 'text/vtt',
        ]);
    }
}
