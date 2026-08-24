<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proctoring violation threshold (S16 B11 — I9)
    |--------------------------------------------------------------------------
    |
    | Number of quiz_proctoring_events an in-progress attempt accumulates
    | before it's auto-submitted and flagged for instructor review.
    | Defaulted to 3 per the plan's own note — a config value, not a
    | hardcoded constant, so it's tunable without a redeploy.
    |
    */
    'proctoring_violation_threshold' => (int) env('ASSESSMENTS_PROCTORING_VIOLATION_THRESHOLD', 3),

];
