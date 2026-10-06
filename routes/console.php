<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Records device sessions idle past 90 days as expired (spec 007, R-3).
Schedule::command('identity:expire-sessions')->daily();

// Moves subscriptions into grace and expiry on their dates (spec 009, US5).
Schedule::command('billing:lapse-subscriptions')->hourly();

// Recomputes each wallet's cached balance and repairs drift (ADR-0005 rule 6, spec 010).
Schedule::command('wallets:verify-balances')->daily();

// Purges tombstones past the 90-day horizon and raises purge horizons (ADR-0008 rule 12, spec 013).
Schedule::command('sync:purge-tombstones')->daily();

// Materialises recurring occurrences and confirms the due ones of automatic rules; hourly so each rule's local midnight is met within the hour (spec 014, R-12).
Schedule::command('wallets:materialise-occurrences')->hourly()->withoutOverlapping();

// Closes pending invitations past their expiry; hourly so the stored status is the truth on the feed (spec 016, R-14).
Schedule::command('sharing:expire-invitations')->hourly()->withoutOverlapping();

// Closes re-enrolment requests past their 72 hours, so "expired" has an instant (spec 018, R-13).
Schedule::command('admin:expire-reenrolment-requests')->everyFiveMinutes()->withoutOverlapping();

// InstaPay claims (spec 020, R-11): unreviewed claims close after 7 days, hourly so the stored status is the truth.
Schedule::command('billing:expire-claims')->hourly()->withoutOverlapping();

// Idempotency records live 24 hours (spec 020, R-2).
Schedule::command('billing:purge-idempotency-records')->hourly()->withoutOverlapping();

// Issued redeem codes past their 30 days expire and close their claims (spec 020, FR-022a).
Schedule::command('billing:expire-redeem-codes')->daily()->withoutOverlapping();

// One reminder, 7 days before an InstaPay billing period ends (spec 020, FR-034).
Schedule::command('billing:send-renewal-reminders')->daily()->withoutOverlapping();

// Address and receipt erased 90 days after a claim closes; the record deleted at 400 (spec 020, FR-039).
Schedule::command('billing:purge-claims')->daily()->withoutOverlapping();

// Exports (spec 022, FR-016, FR-019): expire ready archives after 24 hours, fail stale runs, delete records after 30 days.
Schedule::command('identity:expire-exports')->everyFifteenMinutes()->withoutOverlapping();

// The account purge (spec 022, FR-051): starts every purge whose grace period has ended and resumes the ones under way.
Schedule::command('identity:purge-accounts')->hourly()->withoutOverlapping();

// One reminder when an account's purge is 3 days or less away (spec 022, FR-058).
Schedule::command('identity:send-deletion-reminders')->daily()->withoutOverlapping();

// Devices & push (spec 023, ADR-0025): the hourly recurring-occurrence reminders (not before 09:00 recipient time, FR-023).
Schedule::command('notifications:occurrence-reminders')->hourly()->withoutOverlapping();

// Held notifications released when quiet hours end; notifications stuck sending re-queued (spec 023, FR-017, FR-049).
Schedule::command('notifications:release-held')->everyFiveMinutes()->withoutOverlapping();

// Idle push registrations (270 days) and notification records (30 days) pruned (spec 023, FR-010, FR-043).
Schedule::command('notifications:prune')->daily()->withoutOverlapping();
