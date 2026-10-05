<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Domain\AnonymityFloor;
use App\Modules\Billing\Application\Plans\PlanAdminQuery;
use App\Modules\Billing\Application\Plans\PlanAdminView;
use Illuminate\Contracts\View\View;

/**
 * The plan catalogue (spec 021, US4; FR-031): every plan, active and retired,
 * in display order, by name and never by code. People counts honour the
 * anonymity floor. Reading it is not an audited act (contracts/audit.md).
 */
class PlanCatalogue extends BackOfficeComponent
{
    /** @var list<array<string, mixed>> */
    public array $plans = [];

    public function mount(): void
    {
        $this->plans = array_map(function (PlanAdminView $plan): array {
            $row = $plan->toArray();
            $row['governed_people'] = $plan->governedPeople === null ? null : AnonymityFloor::display($plan->governedPeople);

            return $row;
        }, app(PlanAdminQuery::class)->all());
    }

    public function render(): View
    {
        return view('admin::livewire.plan-catalogue', [
            'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
        ])->layout('admin::page', ['title' => __('admin::plans.title'), 'subtitle' => __('admin::plans.subtitle')]);
    }
}
