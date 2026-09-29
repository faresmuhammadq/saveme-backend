<?php

use App\Modules\Billing\Http\Controllers\BillingReadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plans & Billing routes, under /api/v1 with the `api` group (spec 009)
|--------------------------------------------------------------------------
|
| Reads only: the in-app surface never starts or records a payment (P-10).
| The contract is docs/openapi/paths/billing.yaml.
|
*/

Route::name('billing.')->group(function (): void {
    Route::get('plans', [BillingReadController::class, 'plans'])->name('plans');

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me/entitlements', [BillingReadController::class, 'entitlements'])->name('me.entitlements');
        Route::get('me/subscription', [BillingReadController::class, 'subscription'])->name('me.subscription');
    });
});
