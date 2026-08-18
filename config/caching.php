<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Computed-aggregate cache TTL (S12 B12 — FEATURES.md V2)
    |--------------------------------------------------------------------------
    |
    | Minutes AggregateCache entries (GradebookService::scoreFor(),
    | StudentMetrics::summarize(), InstituteOverviewWidget's own numbers)
    | live before falling back to time-based expiry. This is a safety net,
    | not the primary invalidation mechanism — explicit forget*() calls at
    | the specific write points design doc §4 decision 11 names
    | (AssignmentSubmission graded, QuizAttempt submitted, AttendanceRecord
    | created/updated) do that job. A handful of fields these aggregates
    | return (fee status, paid revenue, active-enrolment count) change via
    | write paths outside that named list (payment webhooks, enrolment
    | approval) with no explicit bust hook in this block's own scope — a
    | short TTL bounds how stale those specific numbers can get rather than
    | leaving them cached indefinitely. A config value, not a hardcoded
    | constant, so it's tunable without a redeploy.
    |
    */
    'aggregate_ttl_minutes' => (int) env('CACHE_AGGREGATE_TTL_MINUTES', 15),

];
