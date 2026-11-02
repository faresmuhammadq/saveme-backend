<?php

/*
|--------------------------------------------------------------------------
| Sharing & invitations (spec 016)
|--------------------------------------------------------------------------
|
| links.invitation_url               Where an invitation link points; the token
|                                    is appended as ?token=. The website or the
|                                    app's deep-link page, per environment.
| links.shared_budget_invitation_url The same for a Shared Budget invitation (ADR-0027).
| links.android_package              The app's package id (it differs per
|                                    flavor); the invitation page opens the
|                                    app with an intent:// link to it.
| invitation_days                    How long an invitation lives.
| throttle.invites_per_day           Invitations one person may create per 24
|                                    hours (429 beyond; replays do not count).
| throttle.mails_per_day_per_address Invitation mails one address receives per
|                                    24 hours across all inviters (silent).
| throttle.token_per_hour            Preview, accept and decline calls per
|                                    person per hour (429 beyond).
| activity_retention_days            How long activity entries are kept; a value
|                                    under 90 is clamped to 90 (ADR-0020).
|
| Read through config() in the Application layer only.
|
*/

return [

    'links' => [
        'invitation_url' => env('SHARING_INVITATION_URL', 'http://saveme-backend.test/invite'),
        'shared_budget_invitation_url' => env('SHARING_SHARED_BUDGET_INVITATION_URL', 'http://saveme-backend.test/invite/shared-budget'),
        'android_package' => env('SHARING_ANDROID_PACKAGE', 'com.fares.saveme'),
    ],

    'invitation_days' => (int) env('SHARING_INVITATION_DAYS', 7),

    'throttle' => [
        'invites_per_day' => (int) env('SHARING_INVITES_PER_DAY', 20),
        'mails_per_day_per_address' => (int) env('SHARING_MAILS_PER_DAY_PER_ADDRESS', 5),
        'token_per_hour' => (int) env('SHARING_TOKEN_PER_HOUR', 30),
    ],

    'activity_retention_days' => (int) env('SHARING_ACTIVITY_RETENTION_DAYS', 90),

];
