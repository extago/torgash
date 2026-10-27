<?php

/*
 * The audit trail's own knobs — in the .env, NOT in the admin's settings.
 *
 * Every one of them can make the trail say less: switch it off, age it out
 * tomorrow, cap it at one row, leave a table out. Kept on a settings page,
 * the admin whose actions the trail exists to record could quietly erase or
 * blind it from the very screen it watches. Read from config('guard.audit'),
 * merged by GuardServiceProvider; `config:cache` bakes them in like any other.
 */
return [
    // Who changed what in the admin, and what it was before.
    'enabled' => (bool) env('GUARD_AUDIT', true),

    // Rows older than this leave by the nightly prune — the only way any row
    // ever leaves. 0 keeps everything.
    'retention_days' => (int) env('GUARD_AUDIT_RETENTION_DAYS', 180),

    // One click can change thousands of rows; past this the trail says so in a
    // row of its own. 0 removes the limit.
    'max_per_request' => (int) env('GUARD_AUDIT_MAX_PER_REQUEST', 200),

    // The same for one background task (a renumbering, an import), recorded
    // as an act of whoever started it. Much higher: a task exists precisely
    // to change many rows, and its whole point in the trail is all of them.
    'max_per_task' => (int) env('GUARD_AUDIT_MAX_PER_TASK', 200000),

    // Tables never recorded, comma separated, on top of the machinery that
    // never is (queues, sessions, this module's own) and the logs their owners
    // declare through guard.audit.ignored_tables.
    'ignore' => array_values(array_filter(array_map('trim', explode(',', (string) env('GUARD_AUDIT_IGNORE', ''))))),
];
