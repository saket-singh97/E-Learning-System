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

    /*
    |--------------------------------------------------------------------------
    | Impossible-travel max plausible speed (S12 B3 — FEATURES.md B11)
    |--------------------------------------------------------------------------
    |
    | km/h. Two successful logins for the same user whose implied travel
    | speed exceeds this are flagged. Defaulted to 900 (commercial flight
    | speed) per the design doc's own §6 note — a config value, not a
    | hardcoded constant, so it's tunable without a redeploy.
    |
    */
    'impossible_travel_max_kmh' => (int) env('SECURITY_IMPOSSIBLE_TRAVEL_MAX_KMH', 900),

    /*
    |--------------------------------------------------------------------------
    | Account-sharing device threshold (S12 B4 — FEATURES.md B12)
    |--------------------------------------------------------------------------
    |
    | Distinct device_sessions.device_fingerprint values, first-seen within
    | a rolling 7-day window, that trip the daily account-sharing sweep.
    | Defaulted to 4 per the design doc's own §6 note — a config value, not
    | a hardcoded constant, so it's tunable without a redeploy.
    |
    */
    'account_sharing_device_threshold' => (int) env('SECURITY_ACCOUNT_SHARING_DEVICE_THRESHOLD', 4),

    /*
    |--------------------------------------------------------------------------
    | Audit log retention (S12 B6 — FEATURES.md B13)
    |--------------------------------------------------------------------------
    |
    | Days an audit_log_entries row is kept before the monthly prune sweep
    | hard-deletes it. Defaulted to 365 per the design doc's own §4
    | decision 6 — a config value, not a hardcoded constant, so it's
    | tunable without a redeploy.
    |
    */
    'audit_log_retention_days' => (int) env('SECURITY_AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | GDPR/DPDP deletion grace window (S12 B9 — FEATURES.md B15 pt.3)
    |--------------------------------------------------------------------------
    |
    | Days between a deletion request and the daily sweep actually executing
    | it. Defaulted to 30 per the design doc's own §6 note — explicitly
    | flagged there as a business decision the actual owner can override,
    | not a hardcoded legal opinion, so it's a config value.
    |
    */
    'gdpr_deletion_grace_days' => (int) env('SECURITY_GDPR_DELETION_GRACE_DAYS', 30),

];
