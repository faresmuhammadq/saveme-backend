<?php

use App\Modules\Identity\Infrastructure\Models\User;

/*
|--------------------------------------------------------------------------
| Authentication (spec 007, research R-1, R-20)
|--------------------------------------------------------------------------
|
| Application users authenticate only with a device-session credential on the
| `api` guard (Sanctum, bearer only). The `web` guard has no user provider, so
| an application user can never hold a browser session — and so can never
| reach the back office, whose own guard arrives with operator sign-in (D2;
| constitution P-08 rule 4, P-18 rule 4).
|
| Password reset is Identity's own single-use link flow, not Laravel's broker.
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'api'),
    ],

    'guards' => [
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        'web' => [
            'driver' => 'session',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

];
