<?php

use App\Modules\Budgeting\Http\Controllers\BudgetController;
use App\Modules\Budgeting\Http\Controllers\BudgetProgressController;
use App\Modules\Budgeting\Http\SharedBudgets\Controllers\SharedBudgetController;
use App\Modules\Budgeting\Http\SharedBudgets\Controllers\SharedBudgetLineController;
use App\Modules\Budgeting\Http\SharedBudgets\Controllers\SharedBudgetMemberController;
use App\Modules\Budgeting\Http\SharedBudgets\Controllers\SharedBudgetProgressController;
use App\Modules\Budgeting\Http\SharedBudgets\Controllers\SharedBudgetReportController;
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

/*
|--------------------------------------------------------------------------
| Shared Budget routes (ADR-0027)
|--------------------------------------------------------------------------
|
| Its own scope: a budget's membership decides, never a wallet's. Leaving
| puts nothing into a budget, so it has no verification gate. The contract
| is docs/openapi/paths/shared-budgets.yaml.
|
*/

Route::name('shared-budgets.')->middleware('auth:api')->group(function (): void {
    Route::get('shared-budgets', [SharedBudgetController::class, 'index'])->name('index');
    Route::get('shared-budgets/{id}', [SharedBudgetController::class, 'show'])->name('show');
    Route::get('shared-budgets/{id}/progress', [SharedBudgetProgressController::class, 'show'])->name('progress');
    Route::get('shared-budgets/{id}/reports/summary', [SharedBudgetReportController::class, 'summary'])->name('reports.summary');
    Route::get('shared-budgets/{id}/reports/trend', [SharedBudgetReportController::class, 'trend'])->name('reports.trend');
    Route::get('shared-budgets/{id}/lines', [SharedBudgetLineController::class, 'index'])->name('lines.index');
    Route::get('shared-budgets/{id}/members', [SharedBudgetMemberController::class, 'index'])->name('members.index');
    Route::post('shared-budgets/{id}/leave', [SharedBudgetMemberController::class, 'leave'])->name('leave');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('shared-budgets/{id}', [SharedBudgetController::class, 'upsert'])->name('upsert');
        Route::patch('shared-budgets/{id}', [SharedBudgetController::class, 'patch'])->name('patch');
        Route::delete('shared-budgets/{id}', [SharedBudgetController::class, 'destroy'])->name('destroy');
        Route::post('shared-budgets/{id}/archive', [SharedBudgetController::class, 'archive'])->name('archive');
        Route::post('shared-budgets/{id}/transfer-ownership', [SharedBudgetController::class, 'transferOwnership'])->name('transfer-ownership');
        Route::put('shared-budgets/{id}/lines/{lineId}', [SharedBudgetLineController::class, 'upsert'])->name('lines.upsert');
        Route::delete('shared-budgets/{id}/lines/{lineId}', [SharedBudgetLineController::class, 'destroy'])->name('lines.destroy');
        Route::delete('shared-budgets/{id}/members/{uid}', [SharedBudgetMemberController::class, 'destroy'])->name('members.destroy');
    });
});
