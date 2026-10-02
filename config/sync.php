<?php

/*
|--------------------------------------------------------------------------
| Sync (spec 013)
|--------------------------------------------------------------------------
|
| tombstone_retention_days  How long a deleted syncable record stays on its
|                           feed before sync:purge-tombstones removes it
|                           (ADR-0008 rule 12, security baseline). 90 is the
|                           floor: a smaller value is clamped to 90 and the
|                           purge logs a warning.
| cursor_key                Signs sync cursors so a client cannot forge or
|                           move one. Defaults to the application key.
|
*/

return [

    'tombstone_retention_days' => (int) env('SYNC_TOMBSTONE_RETENTION_DAYS', 90),

    'cursor_key' => env('SYNC_CURSOR_KEY'),

];
