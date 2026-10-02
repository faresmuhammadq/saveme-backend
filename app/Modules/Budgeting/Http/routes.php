<?php

use App\Modules\Budgeting\Http\Controllers\BudgetController;
use App\Modules\Budgeting\Http\Controllers\BudgetProgressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Budgeting routes, under /api/v1 with the `api` group (spec 015)
|--------------------------------------------------------------------------
|
| User scope: a budget is never inside a wallet. Reads need a signed-in
| caller; every write is also behind the verification gate. The contract is
| docs/openapi/paths/budgets.yaml.
|
*/

Route::name('budgets.')->middleware('auth:api')->group(function (): void {
    Route::get('budgets', [BudgetController::class, 'index'])->name('index');
    Route::get('budgets/{id}', [BudgetController::class, 'show'])->name('show');
    Route::get('budgets/{id}/progress', [BudgetProgressController::class, 'show'])->name('progress.show');
    Route::get('budget-progress', [BudgetProgressController::class, 'index'])->name('progress.index');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('budgets/{id}', [BudgetController::class, 'upsert'])->name('upsert');
        Route::patch('budgets/{id}', [BudgetController::class, 'patch'])->name('patch');
        Route::delete('budgets/{id}', [BudgetController::class, 'destroy'])->name('destroy');
        Route::post('budgets/{id}/archive', [BudgetController::class, 'archive'])->name('archive');
    });
});
