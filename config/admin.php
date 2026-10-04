<?php

/*
|--------------------------------------------------------------------------
| Back office (Admin module)
|--------------------------------------------------------------------------
|
| timezone           The operator timezone instants are shown in, until
|                    operator accounts carry their own (spec 006, R-9).
| supported_locales  The languages the back office offers, and the only
|                    values the language switch and middleware accept.
| rtl_locales        Which of those render right-to-left.
| notifications      Operator notifications (spec 007, R-14): who is emailed,
|                    and how many days count as unread until operator
|                    accounts (D2) carry per-operator read state.
| auth               Operator sign-in (spec 018): the sealing keyring, the
|                    email lookup and recovery-code keys, session and
|                    half-finished sign-in lifetimes, re-confirmation window,
|                    lockout (the baseline's numbers) and code drift.
|
*/

return [

    'timezone' => env('ADMIN_TIMEZONE', 'Africa/Cairo'),

    'supported_locales' => ['en', 'ar'],

    'rtl_locales' => ['ar'],

    'notifications' => [
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ADMIN_NOTIFICATION_RECIPIENTS', '')),
        ))),
        'unread_days' => 7,
    ],

    'auth' => [
        'keys' => json_decode((string) env('ADMIN_KEYS', '{}'), true) ?: [],
        'active_key' => env('ADMIN_ACTIVE_KEY'),
        'lookup_key' => env('ADMIN_LOOKUP_KEY'),
        'code_key' => env('ADMIN_CODE_KEY'),
        'issuer' => 'Save Me Back office',
        'idle_minutes' => 30,
        'absolute_hours' => 12,
        'pending_minutes' => [
            'second_factor' => 5,
            'password_change' => 15,
            'enrolment' => 15,
            'codes_shown' => 15,
        ],
        'reconfirm_minutes' => 10,
        'reenrolment_hours' => 72,
        'temporary_password_hours' => 72,
        'lockout' => [
            'account' => [
                ['failures' => 5, 'minutes' => 15],
                ['failures' => 10, 'minutes' => 60],
            ],
            'cap_minutes' => 60,
            'origin' => ['failures' => 20, 'decay_minutes' => 15],
        ],
        'drift_steps' => 1,
    ],

];
