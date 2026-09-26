<?php

/*
|--------------------------------------------------------------------------
| API runtime (spec 003 contract, implemented by spec 007)
|--------------------------------------------------------------------------
|
| minimum_client_version  Per platform. Below it: 426 CLIENT_VERSION_UNSUPPORTED.
| supported_platforms     Values X-Client-Platform may carry.
| supported_locales       Languages Accept-Language resolves to; the first is
|                         the fallback.
| hsts_max_age            Strict-Transport-Security duration, one year.
| require_tls             Refuse plaintext with 400 INSECURE_TRANSPORT. Off only
|                         where there is no TLS terminator: local and the test
|                         runner (TransportTest switches it on).
| problem_base_url        Base of Problem.type, `{base}/problems/{code}`.
|
*/

return [

    'minimum_client_version' => [
        'android' => env('API_MIN_ANDROID_VERSION', '1.0.0'),
        'web' => env('API_MIN_WEB_VERSION', '1.0.0'),
    ],

    'supported_platforms' => ['android', 'web'],

    'supported_locales' => ['en', 'ar'],

    'hsts_max_age' => 31536000,

    'require_tls' => (bool) env('API_REQUIRE_TLS', ! in_array(env('APP_ENV'), ['local', 'testing'], true)),

    'problem_base_url' => env('API_PROBLEM_BASE_URL', env('APP_URL', 'http://localhost')),

];
