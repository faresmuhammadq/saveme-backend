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
|
*/

return [

    'timezone' => env('ADMIN_TIMEZONE', 'Africa/Cairo'),

    'supported_locales' => ['en', 'ar'],

    'rtl_locales' => ['ar'],

];
