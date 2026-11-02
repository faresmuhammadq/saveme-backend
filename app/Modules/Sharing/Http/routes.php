<?php

use App\Modules\Sharing\Http\Controllers\InvitationController;
use App\Modules\Sharing\Http\Controllers\InvitationResponseController;
use App\Modules\Sharing\Http\Controllers\LeaveController;
use App\Modules\Sharing\Http\Controllers\MemberController;
use App\Modules\Sharing\Http\Controllers\SharedBudgetInvitationController;
use App\Modules\Sharing\Http\Controllers\SharedBudgetInvitationResponseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sharing routes, under /api/v1 with the `api` group (spec 016)
|--------------------------------------------------------------------------
|
| Reads need a signed-in caller. The owner's writes (send, revoke, change a
| role, remove) are also behind the verification gate; sending is also behind
| the inviter's budget, checked before the body. Preview, accept, decline and
| leave need only a signed-in caller: joining or leaving puts nothing into a
| wallet (research R-8). The contract is docs/openapi/paths/sharing.yaml.
|
*/

Route::name('sharing.')->middleware('auth:api')->group(function (): void {
    Route::get('wallets/{id}/invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::get('wallets/{id}/invitations/{iid}', [InvitationController::class, 'show'])->name('invitations.show');

    Route::post('invitations/preview', [InvitationResponseController::class, 'preview'])->name('invitations.preview');
    Route::post('invitations/accept', [InvitationResponseController::class, 'accept'])->name('invitations.accept');
    Route::post('invitations/decline', [InvitationResponseController::class, 'decline'])->name('invitations.decline');

    Route::post('wallets/{id}/leave', LeaveController::class)->name('leave');

    // A Shared Budget's invitations (ADR-0027): the owner's, by link.
    Route::get('shared-budgets/{id}/invitations', [SharedBudgetInvitationController::class, 'index'])->name('shared-budget-invitations.index');
    Route::get('shared-budgets/{id}/invitations/{iid}', [SharedBudgetInvitationController::class, 'show'])->name('shared-budget-invitations.show');
    Route::post('shared-budget-invitations/preview', [SharedBudgetInvitationResponseController::class, 'preview'])->name('shared-budget-invitations.preview');
    Route::post('shared-budget-invitations/accept', [SharedBudgetInvitationResponseController::class, 'accept'])->name('shared-budget-invitations.accept');
    Route::post('shared-budget-invitations/decline', [SharedBudgetInvitationResponseController::class, 'decline'])->name('shared-budget-invitations.decline');

    Route::middleware('address.verified')->group(function (): void {
        Route::put('shared-budgets/{id}/invitations/{iid}', [SharedBudgetInvitationController::class, 'upsert'])->middleware('sharing.invite-budget')->name('shared-budget-invitations.upsert');
        Route::delete('shared-budgets/{id}/invitations/{iid}', [SharedBudgetInvitationController::class, 'destroy'])->name('shared-budget-invitations.destroy');
        Route::put('wallets/{id}/invitations/{iid}', [InvitationController::class, 'upsert'])->middleware('sharing.invite-budget')->name('invitations.upsert');
        Route::delete('wallets/{id}/invitations/{iid}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
        Route::patch('wallets/{id}/members/{uid}', [MemberController::class, 'changeRole'])->name('members.change-role');
        Route::delete('wallets/{id}/members/{uid}', [MemberController::class, 'destroy'])->name('members.destroy');
    });
});
