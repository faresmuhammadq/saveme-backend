<?php

use App\Modules\Notifications\Http\Controllers\DevicesController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferencesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications routes, under /api/v1 with the `api` group (spec 023)
|--------------------------------------------------------------------------
|
| The contract is docs/openapi/paths/notifications.yaml. Not behind the
| verification gate (managing one's own account) and not gated by plan.
|
*/

Route::name('notifications.')->middleware('auth:api')->group(function (): void {
    Route::put('me/devices/{id}', [DevicesController::class, 'update'])->middleware('throttle:notifications-devices')->name('devices.update');
    Route::delete('me/devices/{id}', [DevicesController::class, 'destroy'])->middleware('throttle:notifications-devices')->name('devices.destroy');

    Route::get('me/notification-preferences', [NotificationPreferencesController::class, 'show'])->name('preferences.show');
    Route::put('me/notification-preferences', [NotificationPreferencesController::class, 'update'])->middleware('throttle:notifications-preferences')->name('preferences.update');
});
