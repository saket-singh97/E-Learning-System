<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker (S12 B10 — FEATURES.md V1)
    |--------------------------------------------------------------------------
    |
    | Consecutive failures a provider call needs before the breaker trips
    | open (matches the design doc's own testing-strategy wording: "fails N
    | times in a row"), and how long it then stays open before the next
    | call is let through again as a probe. Both config values, not
    | hardcoded constants, so they're tunable without a redeploy.
    |
    */
    'circuit_breaker_failure_threshold' => (int) env('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),

    'circuit_breaker_cooldown_seconds' => (int) env('CIRCUIT_BREAKER_COOLDOWN_SECONDS', 60),

];
