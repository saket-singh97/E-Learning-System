<?php

namespace App\Http\Controllers\Content;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JoinLiveClassController extends Controller
{
    /**
     * Session id is read off the route rather than method-injected — same
     * domain-route splicing gotcha as StreamLessonController/
     * WatchLessonController (CLAUDE.md).
     */
    public function __invoke(Request $request, LessonAccess $access): RedirectResponse
    {
        $session = BatchSession::findOrFail($request->route('session'));
        $lesson = $session->lesson;

        abort_if($lesson === null, 404);

        $viewer = $request->user();

        abort_unless($access->canJoinLive($lesson, $session, $viewer), 403);

        return redirect()->away(
            $session->resolveLiveClassProvider()->joinUrl($session, $viewer)
        );
    }
}
