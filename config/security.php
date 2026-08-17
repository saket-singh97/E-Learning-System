<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registered-device limit (S12 B1 — FEATURES.md B9)
    |--------------------------------------------------------------------------
    |
    | Max distinct, non-revoked devices a single user may be logged in on at
    | once. A config value, not a hardcoded constant, per the design doc's
    | own §6 note — tunable without a redeploy.
    |
    */
    'device_limit' => (int) env('SECURITY_DEVICE_LIMIT', 2),

];
