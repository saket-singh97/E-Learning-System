<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonStreamSession;
use App\Exceptions\ConcurrentStreamLimitExceededException;
use App\Models\User;

/**
 * D9 (design doc decision 6) — per-lesson, not per-tenant: a viewer may
 * only have one *active* lesson_stream_sessions row (a heartbeat within
 * config('content.stream_session_timeout_seconds')) at a time. Streaming
 * the same lesson again (a reload, a quality switch) is not a conflict;
 * only a different lesson while the first is still active is.
 */
class ConcurrentStreamGuard
{
    public function assertNoOtherActiveStream(User $viewer, Lesson $lesson): void
    {
        $active = LessonStreamSession::query()
            ->where('user_id', $viewer->id)
            ->where('lesson_id', '!=', $lesson->id)
            ->whereNull('ended_at')
            ->where('last_heartbeat_at', '>=', now()->subSeconds(config('content.stream_session_timeout_seconds')))
            ->exists();

        if ($active) {
            throw ConcurrentStreamLimitExceededException::forUser($viewer->id);
        }
    }

    /**
     * Called on every heartbeat (WatchHeartbeatController) — re-asserts the
     * same guard (a second device could start streaming between two
     * heartbeats of the first) before touching this lesson's own session
     * row, then starts it on first heartbeat or just refreshes
     * last_heartbeat_at on every one after.
     */
    public function recordHeartbeat(User $viewer, Lesson $lesson): void
    {
        $this->assertNoOtherActiveStream($viewer, $lesson);

        $session = LessonStreamSession::firstOrNew(
            ['user_id' => $viewer->id, 'lesson_id' => $lesson->id, 'ended_at' => null],
            ['started_at' => now()],
        );

        $session->last_heartbeat_at = now();
        $session->save();
    }
}
