<?php

/*
|--------------------------------------------------------------------------
| Billing (spec 009)
|--------------------------------------------------------------------------
|
| grace_days  Days of grace after a billing period ends, for channels that
|             set none of their own: operator grants and InstaPay until B11
|             overrides it. Google Play sets its own grace (B10).
|
| InstaPay claims (spec 020):
|
| idempotency_hours   How long a stored answer for an Idempotency-Key is kept.
| keys, active_key    The sealing keyring for claim addresses and receipt
|                     images: a JSON map {kid: base64 32-byte key}, like
|                     ADMIN_KEYS, and the id of the key that seals new values.
| instapay.claim_days            Days an unreviewed claim lives before it expires.
| instapay.code_days             Days a redeem code is valid after approval.
| instapay.reminder_days         How many days before its end an InstaPay
|                                subscription's renewal reminder is sent.
| instapay.receipt_max_bytes     Largest decoded receipt image (3 MiB).
| instapay.receipt_link_minutes  How long an operator's receipt link works.
| instapay.erase_after_days      Days after a claim closes until its address,
|                                lookup value and receipt are erased.
| instapay.delete_after_days     Days after a claim closes until the claim and
|                                its code record are deleted.
| instapay.renew_url             Where the renewal reminder points (the
|                                landing page's renewal entry).
| instapay.support_contact       Shown in claim mails.
| instapay.throttle.*            The claim and redeem budgets (429 beyond).
|
| Google Play (spec 024, ADR-0026):
|
| google_play.driver            google | fake | disabled. `disabled` (the
|                               default) grants nothing and answers 503;
|                               `fake` is scripted, for local work and tests,
|                               and is refused at boot elsewhere; `google`
|                               calls androidpublisher v3 and is refused at
|                               boot without the keys below.
| google_play.credentials       The service-account key: a path to the JSON
|                               file, or the JSON base64-encoded. Never
|                               committed; not the FCM key of Notifications.
| google_play.package_name      The Android application id Google reports.
| google_play.account_reference_keys, account_reference_active_key
|                               The keyring (a JSON map {kid: base64 key}) and
|                               the id of the key that creates new Play
|                               Account References (keyed hashes).
| google_play.lookup_key        Base64 key (32 bytes or more) for the keyed
|                               lookup value of a purchase token.
| google_play.push.*            What a Pub/Sub push must prove: the audience
|                               (the push endpoint URL), the sender's service
|                               account email, the Pub/Sub subscription name,
|                               and where Google's certificates are fetched.
| google_play.products          Plan code => Play product id, reference amount
|                               in minor units and currency; seeded
|                               create-missing only (amounts null when unset).
| google_play.fake_script       Path to a JSON file scripting the `fake` driver.
| google_play.throttle.*        Verify and restore budgets per person per hour.
| google_play.unreachable_lapse_hours, ack_alert_hours, unlinked_keep_days,
| inbox_keep_days               Reconciliation and retention horizons.
|
*/

return [

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    'idempotency_hours' => 24,

    'keys' => json_decode((string) env('BILLING_KEYS', '{}'), true) ?: [],

    'active_key' => env('BILLING_ACTIVE_KEY'),

    'instapay' => [
        'claim_days' => 7,
        'code_days' => 30,
        'reminder_days' => 7,
        'receipt_max_bytes' => 3145728,
        'receipt_link_minutes' => 5,
        'erase_after_days' => 90,
        'delete_after_days' => 400,
        'renew_url' => env('BILLING_INSTAPAY_RENEW_URL', 'http://saveme-backend.test/renew'),
        'support_contact' => env('BILLING_SUPPORT_CONTACT', 'support@example.com'),
        'throttle' => [
            'claims_per_hour_per_origin' => 5,
            'claims_per_day_per_address' => 5,
            'redeem_per_hour' => 10,
        ],
    ],

    'google_play' => [
        'driver' => env('BILLING_PLAY_DRIVER', 'disabled'),
        'credentials' => env('BILLING_PLAY_CREDENTIALS'),
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'package_name' => env('BILLING_PLAY_PACKAGE'),
        'account_reference_keys' => json_decode((string) env('BILLING_PLAY_ACCOUNT_KEYS', '{}'), true) ?: [],
        'account_reference_active_key' => env('BILLING_PLAY_ACCOUNT_ACTIVE_KEY'),
        'lookup_key' => env('BILLING_PLAY_LOOKUP_KEY'),
        'push' => [
            'audience' => env('BILLING_PLAY_PUSH_AUDIENCE'),
            'service_account' => env('BILLING_PLAY_PUSH_SERVICE_ACCOUNT'),
            'subscription' => env('BILLING_PLAY_PUSH_SUBSCRIPTION'),
            'certs_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        ],
        'products' => [
            'plus_monthly' => [
                'product_id' => env('BILLING_PLAY_PRODUCT_PLUS_MONTHLY', 'plus_monthly'),
                'amount_minor' => is_numeric(env('BILLING_PLAY_PRICE_PLUS_MONTHLY')) ? (int) env('BILLING_PLAY_PRICE_PLUS_MONTHLY') : null,
                'currency' => env('BILLING_PLAY_CURRENCY', 'EGP'),
            ],
            'plus_yearly' => [
                'product_id' => env('BILLING_PLAY_PRODUCT_PLUS_YEARLY', 'plus_yearly'),
                'amount_minor' => is_numeric(env('BILLING_PLAY_PRICE_PLUS_YEARLY')) ? (int) env('BILLING_PLAY_PRICE_PLUS_YEARLY') : null,
                'currency' => env('BILLING_PLAY_CURRENCY', 'EGP'),
            ],
        ],
        'fake_script' => env('BILLING_PLAY_FAKE_SCRIPT'),
        'throttle' => [
            'verify_per_hour' => 20,
            'restore_per_hour' => 10,
        ],
        'unreachable_lapse_hours' => 72,
        'ack_alert_hours' => 48,
        'unlinked_keep_days' => 10,
        'inbox_keep_days' => 30,
        'timeout' => 10,
        'connect_timeout' => 5,
    ],

];
