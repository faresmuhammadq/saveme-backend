<?php

use App\Modules\Admin\Http\Livewire\Gallery;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office routes
|--------------------------------------------------------------------------
|
| Every back-office route sits under /admin (constitution P-18 rule 4). Until
| operator sign-in lands (brief D2), the only route is the primitives gallery,
| and it exists in the local environment alone (spec 006, FR-041, FR-051).
|
*/

Route::middleware(['web', ResolveAdminLocale::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        if (app()->environment('local')) {
            Route::livewire('/_gallery', Gallery::class)->name('gallery');
        }
    });
