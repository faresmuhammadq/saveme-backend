<?php

use App\Modules\Billing\Http\Controllers\BillingReadController;
use App\Modules\Billing\Http\Controllers\InstapayClaimController;
use App\Modules\Billing\Http\Controllers\RedeemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plans & Billing routes, under /api/v1 with the `api` group (spec 009)
|--------------------------------------------------------------------------
|
| Reads only in the app: the in-app surface never starts or records a payment
| (P-10); the one write is the website's InstaPay claim (spec 020). The
| contracts are docs/openapi/paths/billing.yaml and instapay.yaml.
|
*/

Route::name('billing.')->group(function (): void {
    Route::get('plans', [BillingReadController::class, 'plans'])->name('plans');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me/entitlements', [BillingReadController::class, 'entitlements'])->name('me.entitlements');
        Route::get('me/subscription', [BillingReadController::class, 'subscription'])->name('me.subscription');

        // The in-app "Enter code". No address.verified gate: the service checks verification after the address match (spec 020, FR-026).
        Route::post('billing/redeem', RedeemController::class)->middleware('billing.idempotent')->name('redeem');
    });

    // The website's claim form: public (no bearer credential) and web only (spec 020, ADR-0023).
    Route::post('billing/instapay/claims', InstapayClaimController::class)
        ->middleware(['billing.web-only', 'billing.idempotent:anonymous'])
        ->name('instapay.claims');
});
