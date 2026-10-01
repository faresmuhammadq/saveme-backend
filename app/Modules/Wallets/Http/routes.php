<?php

use App\Modules\Wallets\Http\Controllers\WalletController;
use App\Modules\Wallets\Http\Controllers\WalletMembersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wallets routes, under /api/v1 with the `api` group (spec 010)
|--------------------------------------------------------------------------
|
| Reads need a signed-in caller; every write is also behind the verification
| gate (security baseline, C1). The contract is docs/openapi/paths/wallets.yaml.
|
*/

Route::name('wallets.')->middleware('auth:api')->group(function (): void {
    Route::get('wallets', [WalletController::class, 'index'])->name('index');
    Route::get('wallets/{id}', [WalletController::class, 'show'])->name('show');
    Route::get('wallets/{id}/members', [WalletMembersController::class, 'index'])->name('members.index');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('wallets/{id}', [WalletController::class, 'upsert'])->name('upsert');
        Route::patch('wallets/{id}', [WalletController::class, 'patch'])->name('patch');
        Route::delete('wallets/{id}', [WalletController::class, 'destroy'])->name('destroy');
        Route::post('wallets/{id}/archive', [WalletController::class, 'archive'])->name('archive');
        Route::post('wallets/{id}/transfer-ownership', [WalletController::class, 'transferOwnership'])->name('transfer-ownership');
    });
});
