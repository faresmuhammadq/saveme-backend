<?php

use App\Modules\Identity\Http\Controllers\DeletionRequestController;
use App\Modules\Identity\Http\Controllers\ExportsController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Identity\Http\Controllers\SessionsController;
use App\Modules\Identity\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity routes, under /api/v1 with the `api` group (spec 007)
|--------------------------------------------------------------------------
|
| The contract for each operation is docs/openapi/paths/identity.yaml.
|
*/

Route::name('identity.')->group(function (): void {
    Route::post('auth/register', RegisterController::class)->middleware('throttle:identity-register')->name('register');
    Route::post('auth/login', LoginController::class)->name('login');
    Route::post('auth/verify-email', [VerificationController::class, 'verify'])->name('verify-email');
    Route::post('auth/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:identity-reset')->name('forgot-password');
    Route::post('auth/reset-password', [PasswordController::class, 'reset'])->name('reset-password');

    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/resend-verification', [VerificationController::class, 'resend'])->middleware('throttle:identity-resend')->name('resend-verification');
        Route::get('auth/sessions', [SessionsController::class, 'index'])->name('sessions.index');
        Route::delete('auth/sessions', [SessionsController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('auth/sessions/{id}', [SessionsController::class, 'destroy'])->name('sessions.destroy');
        Route::post('auth/logout', [SessionsController::class, 'logout'])->name('logout');
        Route::post('auth/change-password', [PasswordController::class, 'change'])->name('change-password');
        Route::get('account/deletion-request', [DeletionRequestController::class, 'show'])->name('deletion-request.show');
        Route::post('account/deletion-request', [DeletionRequestController::class, 'store'])->name('deletion-request.store');
        Route::delete('account/deletion-request', [DeletionRequestController::class, 'destroy'])->name('deletion-request.destroy');
        Route::post('exports', [ExportsController::class, 'store'])->name('exports.store');
        Route::get('exports', [ExportsController::class, 'index'])->name('exports.index');
        Route::get('exports/{id}', [ExportsController::class, 'show'])->name('exports.show');
        Route::get('me', [ProfileController::class, 'show'])->name('me');
        Route::put('me/profile', [ProfileController::class, 'update'])->name('me.profile');
    });

    // Google Sign-In is reserved and switched off (FR-080): while off, the
    // route does not exist and the API fallback answers 404.
    if (config('identity.google.enabled')) {
        Route::post('auth/google', fn () => abort(404))->name('google');
    }
});
