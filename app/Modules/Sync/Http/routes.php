<?php

use App\Modules\Sync\Http\Controllers\SharedBudgetChangesController;
use App\Modules\Sync\Http\Controllers\SharedBudgetHeadsController;
use App\Modules\Sync\Http\Controllers\SyncBatchController;
use App\Modules\Sync\Http\Controllers\UserChangesController;
use App\Modules\Sync\Http\Controllers\WalletChangesController;
use App\Modules\Sync\Http\Controllers\WalletHeadsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sync routes, under /api/v1 with the `api` group (spec 013)
|--------------------------------------------------------------------------
|
| Two change feeds and one batch upload, all behind a signed-in caller. The
| batch has no verification gate of its own: each item runs through the
| route of its single write, which has the gate where the write has it. The
| contract is docs/openapi/paths/sync.yaml.
|
*/

Route::name('sync.')->middleware('auth:api')->group(function (): void {
    Route::get('sync/user/changes', UserChangesController::class)->name('user.changes');
    Route::get('sync/wallets/heads', WalletHeadsController::class)->name('wallet.heads');
    Route::get('sync/wallets/{id}/changes', WalletChangesController::class)->name('wallet.changes');
    Route::get('sync/shared-budgets/heads', SharedBudgetHeadsController::class)->name('shared-budget.heads');
    Route::get('sync/shared-budgets/{id}/changes', SharedBudgetChangesController::class)->name('shared-budget.changes');
    Route::post('sync/batch', SyncBatchController::class)->name('batch');
});
