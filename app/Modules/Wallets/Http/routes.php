<?php

use App\Modules\Wallets\Http\Controllers\ActivityController;
use App\Modules\Wallets\Http\Controllers\CategoryController;
use App\Modules\Wallets\Http\Controllers\CategoryReferenceController;
use App\Modules\Wallets\Http\Controllers\PlatformCategoryController;
use App\Modules\Wallets\Http\Controllers\Recurring\OccurrenceController;
use App\Modules\Wallets\Http\Controllers\Recurring\RecurringRuleController;
use App\Modules\Wallets\Http\Controllers\TransactionController;
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
    Route::get('wallets/{id}/activity', [ActivityController::class, 'index'])->name('activity.index');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('wallets/{id}', [WalletController::class, 'upsert'])->name('upsert');
        Route::patch('wallets/{id}', [WalletController::class, 'patch'])->name('patch');
        Route::delete('wallets/{id}', [WalletController::class, 'destroy'])->name('destroy');
        Route::post('wallets/{id}/archive', [WalletController::class, 'archive'])->name('archive');
        Route::post('wallets/{id}/transfer-ownership', [WalletController::class, 'transferOwnership'])->name('transfer-ownership');
    });
});

/*
| Categories (spec 011). Reads need a signed-in caller; every write is also
| behind the verification gate. The contract is docs/openapi/paths/categories.yaml.
*/

Route::name('categories.')->middleware('auth:api')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('index');
    Route::get('categories/{id}', [CategoryController::class, 'show'])->name('show');
    Route::get('platform-categories', [PlatformCategoryController::class, 'index'])->name('platform.index');
    Route::get('wallets/{id}/category-references', [CategoryReferenceController::class, 'index'])->name('references.index');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('categories/{id}', [CategoryController::class, 'upsert'])->name('upsert');
        Route::patch('categories/{id}', [CategoryController::class, 'patch'])->name('patch');
        Route::delete('categories/{id}', [CategoryController::class, 'destroy'])->name('destroy');
        Route::post('categories/{id}/archive', [CategoryController::class, 'archive'])->name('archive');
    });
});

/*
| Transactions (spec 012). Reads need a signed-in caller; every write is also
| behind the verification gate. The contract is docs/openapi/paths/transactions.yaml.
*/

Route::name('transactions.')->middleware('auth:api')->group(function (): void {
    Route::get('wallets/{id}/transactions', [TransactionController::class, 'index'])->name('index');
    Route::get('wallets/{id}/transactions/{tid}', [TransactionController::class, 'show'])->name('show');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('wallets/{id}/transactions/{tid}', [TransactionController::class, 'upsert'])->name('upsert');
        Route::patch('wallets/{id}/transactions/{tid}', [TransactionController::class, 'patch'])->name('patch');
        Route::delete('wallets/{id}/transactions/{tid}', [TransactionController::class, 'destroy'])->name('destroy');
    });
});

/*
| Recurring rules and their occurrences (spec 014). Reads need a signed-in
| caller; every write is also behind the verification gate. The contract is
| docs/openapi/paths/recurring.yaml.
*/

Route::name('recurring.')->middleware('auth:api')->group(function (): void {
    Route::get('wallets/{id}/recurring-rules', [RecurringRuleController::class, 'index'])->name('rules.index');
    Route::get('wallets/{id}/recurring-rules/{rid}', [RecurringRuleController::class, 'show'])->name('rules.show');
    Route::get('wallets/{id}/occurrences', [OccurrenceController::class, 'index'])->name('occurrences.index');
    Route::get('wallets/{id}/occurrences/{oid}', [OccurrenceController::class, 'show'])->name('occurrences.show');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('wallets/{id}/recurring-rules/{rid}', [RecurringRuleController::class, 'upsert'])->name('rules.upsert');
        Route::delete('wallets/{id}/recurring-rules/{rid}', [RecurringRuleController::class, 'destroy'])->name('rules.destroy');
        Route::post('wallets/{id}/recurring-rules/{rid}/materialise', [RecurringRuleController::class, 'materialise'])->name('rules.materialise');
        Route::put('wallets/{id}/occurrences/{oid}', [OccurrenceController::class, 'save'])->name('occurrences.save');
    });
});
