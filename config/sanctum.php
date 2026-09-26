<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum — bearer credentials only (spec 007, research R-1)
|--------------------------------------------------------------------------
|
| Sanctum is used as a bearer-token guard and nothing else. Each Device
| Session is the token record (App\Modules\Identity\Infrastructure\Models\
| DeviceSession), registered by the Identity persistence provider.
|
| stateful     Empty: no cookie/SPA authentication. A browser session never
|              authenticates an API call.
| guard        Empty: no fallback to the web session guard, so an app
|              credential is the only way in.
| expiration   Null: expiry is 90 days of inactivity, enforced by the
|              Identity provider's authentication callback (R-3), not by an
|              absolute age.
| routes       False: the csrf-cookie route belongs to SPA mode, unused here.
|
*/

return [

    'stateful' => [],

    'guard' => [],

    'expiration' => null,

    'token_prefix' => '',

    'routes' => false,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
