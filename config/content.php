<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Video-lesson completion watch threshold (S13 B6 — design doc §6 note)
    |--------------------------------------------------------------------------
    |
    | Percent of video_duration_seconds a learner must reach (via
    | video_watch_events) before a video lesson counts as complete for
    | sequential-unlocking purposes. Defaulted to 90 — a learner who
    | watched to 100% but paused 3 seconds before the credits shouldn't be
    | blocked from the next lesson. A config value, not a hardcoded
    | constant, so it's tunable without a redeploy.
    |
    */
    'lesson_completion_watch_threshold' => (int) env('CONTENT_LESSON_COMPLETION_WATCH_THRESHOLD', 90),

    /*
    |--------------------------------------------------------------------------
    | Concurrent-playback session staleness (S14 B0 — design doc decision 6)
    |--------------------------------------------------------------------------
    |
    | A lesson_stream_sessions row counts as "active" (blocks a second
    | concurrent stream) only while its last_heartbeat_at is within this
    | many seconds. Default 120 — generous enough that a tab switch or a
    | brief network blip doesn't false-positive a device switch.
    |
    */
    'stream_session_timeout_seconds' => (int) env('CONTENT_STREAM_SESSION_TIMEOUT_SECONDS', 120),

];
