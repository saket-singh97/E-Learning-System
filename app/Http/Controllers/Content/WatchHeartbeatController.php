<?php

namespace App\Http\Controllers\Content;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Content\Services\LessonAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchHeartbeatController extends Controller
{
    public function __invoke(Request $request, LessonAccess $access): JsonResponse
    {
        $viewer = $request->user();

        abort_unless($viewer, 401);

        $lesson = Lesson::findOrFail($request->route('lesson'));

        // Same gate as the stream itself, so progress can only be recorded for
        // a lesson this viewer is actually allowed to watch. isPlayable was
        // missing here even though StreamLessonController requires it — a
        // lesson with a failed/processing upload could still collect watch
        // events with no video ever served for them.
        abort_unless($access->isVisibleTo($lesson, $viewer), 404);
        abort_unless($access->canStream($lesson, $viewer), 403);
        abort_unless($access->isPlayable($lesson), 404);

        $data = $request->validate([
            'start_s' => ['required', 'integer', 'min:0'],
            'end_s' => ['required', 'integer', 'gte:start_s'],
            'duration_s' => ['nullable', 'integer', 'min:1'],
        ]);

        VideoWatchEvent::create([
            'user_id' => $viewer->id,
            'lesson_id' => $lesson->id,
            'start_s' => $data['start_s'],
            'end_s' => $data['end_s'],
        ]);

        // The player reports the <video> element's own duration on every
        // heartbeat; only the first one that arrives is kept — it's a fixed
        // property of the stored file, not something later heartbeats should
        // keep overwriting. This is also the only place anything in the app
        // ever populates video_duration_seconds (S5 B7's coverage job reads
        // it, nothing wrote it before this).
        if (filled($data['duration_s'] ?? null) && blank($lesson->video_duration_seconds)) {
            $lesson->update(['video_duration_seconds' => $data['duration_s']]);
        }

        return response()->json(['ok' => true]);
    }
}
