<?php

/*
|--------------------------------------------------------------------------
| Notifications (spec 023, ADR-0025)
|--------------------------------------------------------------------------
|
| push.driver               fcm | log | disabled. `log` delivers nothing and is
|                           refused at boot outside local and testing; `fcm`
|                           is refused at boot without a project id and a
|                           readable service-account key; `disabled` records
|                           every notification as skipped.
| push.fcm.project_id       The Firebase project id.
| push.fcm.credentials      The service-account key: a path to the JSON file,
|                           or the JSON base64-encoded. Never committed.
| push.fcm.timeout          Seconds for one send; connect_timeout for the
|                           connection.
| push.fcm.token_uri        The OAuth token endpoint when the key names none.
| keys, active_key          The sealing keyring for push tokens: a JSON map
|                           {kid: base64 32-byte key}, and the id of the key
|                           that seals new values.
| lookup_key                Base64 HMAC key (32 bytes or more) for the token
|                           lookup value.
| rate.per_hour, per_day    Pushes per person that are sent; beyond, skipped.
| summary_threshold         More reminders than this for one person in one
|                           pass collapse into one summary.
| reminder_hour             Local hour before which no reminder is sent.
| registration_idle_days    A registration not refreshed for this long ends.
| retention_days            Days notification and delivery records are kept.
| defaults.quiet_hours      Used until a person saves preferences (minutes
|                           since midnight).
| stale_sending_minutes     A notification left `sending` this long is
|                           re-queued by the release sweep.
|
*/

return [

    'push' => [
        'driver' => env('NOTIFICATIONS_PUSH_DRIVER', 'disabled'),

        'fcm' => [
            'project_id' => env('NOTIFICATIONS_FCM_PROJECT_ID'),
            'credentials' => env('NOTIFICATIONS_FCM_CREDENTIALS'),
            'timeout' => 10,
            'connect_timeout' => 5,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ],
    ],

    'keys' => json_decode((string) env('NOTIFICATIONS_KEYS', '{}'), true) ?: [],

    'active_key' => env('NOTIFICATIONS_ACTIVE_KEY'),

    'lookup_key' => env('NOTIFICATIONS_LOOKUP_KEY'),

    'rate' => [
        'per_hour' => 10,
        'per_day' => 30,
    ],

    'summary_threshold' => 3,

    'reminder_hour' => 9,

    'registration_idle_days' => 270,

    'retention_days' => 30,

    'defaults' => [
        'quiet_hours' => [
            'enabled' => true,
            'start' => 1320,
            'end' => 480,
        ],
    ],

    'stale_sending_minutes' => 15,

];
