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

];
