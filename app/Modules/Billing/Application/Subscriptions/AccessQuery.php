<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;

/**
 * What an operator sees of a person's access, and what they may do about it
 * (spec 021, FR-021, FR-026 to FR-029). Read only: the commands are
 * `OperatorSubscriptions`, which enforce the same rules this view reports.
 */
final class AccessQuery
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
    ) {}

    public function forUser(string $userId): AccessView
    {
        $records = $this->subscriptions->forUser($userId);
        usort($records, fn (SubscriptionRecord $a, SubscriptionRecord $b): int => [$b->createdAt->format('U.u'), $b->id] <=> [$a->createdAt->format('U.u'), $a->id]);

        $names = [];
        $views = [];

        foreach ($records as $record) {
            $names[$record->planId] ??= $this->planName($record->planId);
            $revoked = $record->status === SubscriptionStatus::Revoked;

            $views[] = new SubscriptionView(
                id: $record->id,
                planId: $record->planId,
                planName: $names[$record->planId],
                provider: $record->provider,
                status: $record->status->value,
                periodStart: $record->currentPeriodStart,
                periodEnd: $record->currentPeriodEnd,
                graceUntil: $record->graceUntil,
                createdAt: $record->createdAt,
                canExtend: $record->provider === 'manual' && $record->currentPeriodEnd !== null && ! $revoked,
                canRevoke: in_array($record->provider, ['manual', 'instapay'], true) && ! $revoked,
            );
        }

        return new AccessView(GoverningSubscription::among($records, now()->toDateTimeImmutable())?->id, $views);
    }

    /**
     * @return array{ar: string, en: string}
     */
    private function planName(string $planId): array
    {
        $plan = $this->plans->find($planId);

        return $plan === null ? ['en' => '', 'ar' => ''] : $plan->name;
    }

    /**
     * The plans an operator may grant: active and not the default, in
     * catalogue order, named in both languages. No code, no price.
     *
     * @return list<array{id: string, name: array{ar: string, en: string}}>
     */
    public function grantablePlans(): array
    {
        $offered = [];

        foreach ($this->plans->activeCatalogue() as $plan) {
            if (! $plan->isDefault) {
                $offered[] = ['id' => $plan->id, 'name' => $plan->name];
            }
        }

        return $offered;
    }
}
