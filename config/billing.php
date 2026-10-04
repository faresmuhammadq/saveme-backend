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

];
