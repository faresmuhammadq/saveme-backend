<?php

use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Reporting\Http\Controllers\WalletReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting routes, under /api/v1 with the `api` group (spec 019)
|--------------------------------------------------------------------------
|
| Read-only: a signed-in caller, no verification gate, no entitlement, no
| rate limit. The contract is docs/openapi/paths/reports.yaml.
|
*/

Route::name('reports.')->middleware('auth:api')->group(function (): void {
    Route::get('wallets/{id}/reports/summary', [WalletReportController::class, 'summary'])->name('wallet.summary');
    Route::get('wallets/{id}/reports/by-category', [WalletReportController::class, 'byCategory'])->name('wallet.by-category');
    Route::get('wallets/{id}/reports/trend', [WalletReportController::class, 'trend'])->name('wallet.trend');

    Route::get('reports/summary', [ReportController::class, 'summary'])->name('summary');
    Route::get('reports/by-category', [ReportController::class, 'byCategory'])->name('by-category');
    Route::get('reports/trend', [ReportController::class, 'trend'])->name('trend');
});
