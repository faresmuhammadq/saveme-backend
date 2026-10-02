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
