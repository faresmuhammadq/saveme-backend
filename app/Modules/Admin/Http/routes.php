<?php

use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Controllers\EnrolmentController;
use App\Modules\Admin\Http\Controllers\LostDeviceController;
use App\Modules\Admin\Http\Controllers\NewPasswordController;
use App\Modules\Admin\Http\Controllers\SecondFactorController;
use App\Modules\Admin\Http\Controllers\SignInController;
use App\Modules\Admin\Http\Controllers\SignOutController;
use App\Modules\Admin\Http\Livewire\Gallery;
use App\Modules\Admin\Http\Livewire\Overview;
use App\Modules\Admin\Http\Livewire\ReenrolmentRequests;
use App\Modules\Admin\Http\Livewire\ReplaceAuthenticator;
use App\Modules\Admin\Http\Livewire\SecurityPage;
use App\Modules\Admin\Http\Middleware\BackOfficeHeaders;
use App\Modules\Admin\Http\Middleware\EnsureOperatorSignedIn;
use App\Modules\Admin\Http\Middleware\EnsurePendingStage;
use App\Modules\Admin\Http\Middleware\RedirectSignedInOperator;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office routes
|--------------------------------------------------------------------------
|
| Every back-office route sits under /admin (constitution P-18 rule 4) in one
| of three groups (spec 018, contracts/routes-and-screens.md):
|
|   guest     the first sign-in step, for anyone not fully signed in
|   pending   the steps of a half-finished sign-in, each gated on its stage
|   operator  everything else, behind EnsureOperatorSignedIn
|
| A route a later feature registers in the operator group is guarded by
| default. The only unguarded route besides the sign-in steps is the
| primitives gallery, in the local environment alone (spec 006, FR-041).
|
*/

Route::middleware(['web', ResolveAdminLocale::class, BackOfficeHeaders::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::middleware(RedirectSignedInOperator::class)->group(function (): void {
            Route::get('/sign-in', [SignInController::class, 'show'])->name('sign-in');
            Route::post('/sign-in', [SignInController::class, 'submit'])->name('sign-in.submit');
        });

        Route::post('/sign-in/restart', [SignInController::class, 'restart'])->name('sign-in.restart');

        Route::middleware(EnsurePendingStage::for(SignInStage::SecondFactor))->group(function (): void {
            Route::get('/sign-in/code', [SecondFactorController::class, 'show'])->name('sign-in.code');
            Route::post('/sign-in/code', [SecondFactorController::class, 'submit'])->name('sign-in.code.submit');
            Route::get('/sign-in/recovery', [SecondFactorController::class, 'showRecovery'])->name('sign-in.recovery');
            Route::post('/sign-in/recovery', [SecondFactorController::class, 'submitRecovery'])->name('sign-in.recovery.submit');
            Route::get('/sign-in/lost-device', [LostDeviceController::class, 'show'])->name('sign-in.lost-device');
            Route::post('/sign-in/lost-device', [LostDeviceController::class, 'submit'])->name('sign-in.lost-device.submit');
        });

        Route::middleware(EnsurePendingStage::for(SignInStage::PasswordChange))->group(function (): void {
            Route::get('/password/new', [NewPasswordController::class, 'show'])->name('password.new');
            Route::post('/password/new', [NewPasswordController::class, 'submit'])->name('password.new.submit');
        });

        Route::middleware(EnsurePendingStage::for(SignInStage::Enrolment, SignInStage::CodesShown))->group(function (): void {
            Route::get('/enrol', [EnrolmentController::class, 'show'])->name('enrol');
            Route::post('/enrol', [EnrolmentController::class, 'submit'])->name('enrol.submit');
            Route::post('/enrol/done', [EnrolmentController::class, 'done'])->name('enrol.done');
        });

        Route::middleware(EnsureOperatorSignedIn::class)->group(function (): void {
            Route::post('/sign-out', SignOutController::class)->name('sign-out');
            Route::livewire('/', Overview::class)->name('overview');
            Route::livewire('/security', SecurityPage::class)->name('security');
            Route::livewire('/security/authenticator', ReplaceAuthenticator::class)->name('security.authenticator');
            Route::livewire('/reenrolment-requests', ReenrolmentRequests::class)->name('reenrolment-requests');
        });

        if (app()->environment('local')) {
            Route::livewire('/_gallery', Gallery::class)->name('gallery');
        }
    });
