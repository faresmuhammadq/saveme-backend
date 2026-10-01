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
