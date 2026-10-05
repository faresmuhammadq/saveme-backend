<?php

namespace App\Modules\Billing\Application\Export;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Billing's file of an account export (spec 022, FR-002): the person's
 * subscriptions — plan code and name, channel, status, period instants. No
 * provider reference, no price, no claim and no redeem code.
 */
final class SubscriptionSection implements ExportSection
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
    ) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        if (! $target->isAccount()) {
            return;
        }

        $writer->declare('subscriptions.json');

        foreach ($this->subscriptions->forUser($target->userId) as $subscription) {
            $plan = $this->plans->find($subscription->planId);
            $instant = fn (?DateTimeImmutable $at): ?string => $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

            $writer->append('subscriptions.json', [
                'id' => $subscription->id,
                'plan_code' => $plan?->code->value,
                'plan_name' => $plan === null ? null : $plan->name,
                'provider' => $subscription->provider,
                'status' => $subscription->status->value,
                'current_period_start' => $instant($subscription->currentPeriodStart),
                'current_period_end' => $instant($subscription->currentPeriodEnd),
                'grace_until' => $instant($subscription->graceUntil),
                'auto_renew' => $subscription->autoRenew,
                'created_at' => $instant($subscription->createdAt),
            ]);
        }
    }
}
