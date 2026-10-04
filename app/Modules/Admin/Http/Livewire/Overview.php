<?php

namespace App\Modules\Admin\Http\Livewire;

use Illuminate\Contracts\View\View;

/**
 * The page an operator lands on after signing in (spec 018, R-18). A minimal
 * guarded page: an empty state for the screens later features add, and an
 * alert when recovery codes run low. B12 replaces its body.
 */
class Overview extends BackOfficeComponent
{
    public const LOW_CODES = 3;

    public int $recoveryCodesLeft = 10;

    public function mount(): void
    {
        $this->recoveryCodesLeft = $this->operator()->recoveryCodesLeft;
    }

    public function render(): View
    {
        return view('admin::livewire.overview')
            ->layout('admin::page', ['title' => __('admin::auth.overview.title')]);
    }
}
