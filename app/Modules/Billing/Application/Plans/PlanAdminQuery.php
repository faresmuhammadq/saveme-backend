<?php

namespace App\Modules\Billing\Application\Plans;

use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Plans\PlanEdit;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;

/**
 * What an operator sees of the plan catalogue (spec 021, FR-031, FR-036,
 * FR-037). Read only; the commands are `PlanAdministration`.
 */
final class PlanAdminQuery
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ClaimRepository $claims,
    ) {}

    /**
     * Every plan in display order.
     *
     * @return list<PlanAdminView>
     */
    public function all(): array
    {
        $governed = $this->subscriptions->governedCountsByPlan(now()->toDateTimeImmutable());

        return array_map(fn (PlanRecord $plan): PlanAdminView => $this->view($plan, $governed), $this->plans->all());
    }

    public function find(string $planId): ?PlanAdminView
    {
        $plan = $this->plans->find($planId);

        return $plan === null ? null : $this->view($plan, $this->subscriptions->governedCountsByPlan(now()->toDateTimeImmutable()));
    }

    /**
     * Checks a draft against the stored plan without writing.
     *
     * @return list<string> changed field names, sorted
     *
     * @throws PlanEditInvalid
     * @throws NothingToSave
     */
    public function preview(string $planId, PlanChangeSet $changes): array
    {
        $plan = $this->plans->find($planId);

        return $plan === null ? throw new NothingToSave : PlanEdit::of($plan, $changes)->fields;
    }

    /**
     * @param  array<string, int>  $governed
     */
    private function view(PlanRecord $plan, array $governed): PlanAdminView
    {
        $instapay = null;
        $googlePlay = [];

        foreach ($plan->prices as $price) {
            if ($price['channel'] === 'instapay') {
                $instapay = ['amount_minor' => $price['amount_minor'], 'currency' => $price['currency']];
            } elseif ($price['channel'] === 'google_play') {
                $googlePlay[] = ['product_id' => $price['product_id'], 'amount_minor' => $price['amount_minor'], 'currency' => $price['currency']];
            }
        }

        return new PlanAdminView(
            id: $plan->id,
            name: $plan->name,
            periodType: $plan->periodType,
            periodDays: $plan->periodDays,
            isDefault: $plan->isDefault,
            status: $plan->status,
            sortOrder: $plan->sortOrder,
            features: $plan->features,
            instapayPrice: $instapay,
            googlePlayPrices: $googlePlay,
            version: $plan->version,
            governedPeople: $plan->isDefault ? null : ($governed[$plan->id] ?? 0),
            submittedClaims: $this->claims->countSubmittedForPlan($plan->id, now()->toDateTimeImmutable()),
        );
    }
}
