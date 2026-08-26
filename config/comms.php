<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Help desk SLA hours (S19 N16 — design doc §4 decision 7)
    |--------------------------------------------------------------------------
    |
    | Hours from a ticket's own created_at to its sla_due_at, per priority.
    | Tenant-independent for this slice — a per-institute override is a
    | real but unrequested future refinement, same "single hardcoded value,
    | no per-institute override yet" starting point H10 (S16) took before a
    | later slice gave it one.
    |
    */
    'help_desk_sla_hours' => [
        'low' => (int) env('COMMS_HELP_DESK_SLA_HOURS_LOW', 72),
        'normal' => (int) env('COMMS_HELP_DESK_SLA_HOURS_NORMAL', 48),
        'high' => (int) env('COMMS_HELP_DESK_SLA_HOURS_HIGH', 24),
    ],

];
