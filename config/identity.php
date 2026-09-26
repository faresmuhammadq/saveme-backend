<?php

/*
|--------------------------------------------------------------------------
| Identity (spec 007)
|--------------------------------------------------------------------------
|
| keys                     Key-encryption keyring, {kid: base64 32-byte key}.
|                          Wraps each user's data key (research R-6). Lives in
|                          the deployment's secret store, never the database.
| active_key               The kid new data keys are wrapped with.
| lookup_key               Base64 HMAC key for the address lookup value (R-7).
|                          Separate from the keyring by baseline rule.
| lockout                  Account thresholds (consecutive failures → minutes),
|                          the 60-minute cap, and the per-origin limit (R-9).
| throttle                 Route budgets recorded in the security baseline.
| links                    Where mailed links land (website or app deep link),
|                          and their lifetimes in minutes (baseline tokens).
| session_inactivity_days  A session unused this long stops working (R-3).
| deletion_grace_days      Days between a deletion request and the purge.
| default_timezone         Profile timezone when registration sends none.
| google                   Google Sign-In, reserved and switched off (FR-080).
|
*/

return [

    'keys' => json_decode((string) env('IDENTITY_KEYS', '{}'), true) ?: [],

    'active_key' => env('IDENTITY_ACTIVE_KEY'),

    'lookup_key' => env('IDENTITY_LOOKUP_KEY'),

    'lockout' => [
        'account' => [
            ['failures' => 5, 'minutes' => 15],
            ['failures' => 10, 'minutes' => 60],
        ],
        'cap_minutes' => 60,
        'origin' => ['failures' => 20, 'decay_minutes' => 15],
    ],

    'throttle' => [
        'register_per_hour_per_origin' => 10,
        'resend_per_hour_per_address' => 3,
        'reset_per_hour_per_address' => 3,
    ],

    'links' => [
        'verify_url' => env('IDENTITY_VERIFY_URL', 'http://saveme-backend.test/verify-email'),
        'reset_url' => env('IDENTITY_RESET_URL', 'http://saveme-backend.test/reset-password'),
        'verify_minutes' => 1440,
        'reset_minutes' => 60,
    ],

    'session_inactivity_days' => 90,

    'deletion_grace_days' => 30,

    'default_timezone' => 'Africa/Cairo',

    'google' => [
        'enabled' => (bool) env('IDENTITY_GOOGLE_ENABLED', false),
    ],

];
