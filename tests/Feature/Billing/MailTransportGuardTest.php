<?php

use App\Modules\Billing\Application\BillingServiceProvider;

/*
 * The approval mail carries a redeem code, so a mail transport that writes
 * messages to the log is refused outside local and testing (spec 020, FR-040).
 */

function bootBillingWith(string $mailer, string $environment): void
{
    config(['mail.default' => $mailer]);
    app()->detectEnvironment(fn () => $environment);
    (new BillingServiceProvider(app()))->boot();
}

test('the log and array transports are refused in production and staging', function (string $mailer, string $environment) {
    expect(fn () => bootBillingWith($mailer, $environment))->toThrow(RuntimeException::class, 'redeem code');
})->with([['log', 'production'], ['array', 'production'], ['log', 'staging']]);

test('they are allowed locally and in tests, and a real transport is allowed anywhere', function (string $mailer, string $environment) {
    bootBillingWith($mailer, $environment);

    expect(true)->toBeTrue();
})->with([['log', 'local'], ['array', 'testing'], ['smtp', 'production'], ['ses', 'staging']]);
