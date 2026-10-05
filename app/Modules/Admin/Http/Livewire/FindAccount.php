<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Accounts\AccountLookup;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use Illuminate\Contracts\View\View;

/**
 * Find one person by exact address or exact account id (spec 021, US2;
 * FR-020, FR-025, FR-061). A hit goes straight to the account page, which
 * records the opening; a miss answers the same whatever was typed. The
 * address typed is flashed (normalised) for the next request only, so the
 * page can say how it was found, and is never kept in component state.
 */
class FindAccount extends BackOfficeComponent
{
    public const FLASH = 'account_found_by';

    public string $term = '';

    public bool $missed = false;

    public ?int $budgetMinutes = null;

    public function find(): void
    {
        $this->missed = false;
        $this->budgetMinutes = null;
        $this->resetErrorBag('term');

        $term = trim($this->term);

        if ($term === '') {
            $this->addError('term', __('admin::accounts.find.empty'));

            return;
        }

        try {
            $result = app(AccountLookup::class)->find($this->operatorId(), $term);
        } catch (LookupBudgetSpent $spent) {
            $this->budgetMinutes = $spent->minutes;

            return;
        }

        if (! $result['found']) {
            $this->missed = true;

            return;
        }

        if ($result['normalised'] !== null) {
            session()->flash(self::FLASH, ['user' => $result['user_id'], 'address' => $result['normalised']]);
        }

        $this->redirectRoute('admin.accounts.show', ['user' => $result['user_id']]);
    }

    public function render(): View
    {
        return view('admin::livewire.find-account')
            ->layout('admin::page', ['title' => __('admin::accounts.title'), 'subtitle' => __('admin::accounts.subtitle')]);
    }
}
