<?php

namespace App\Modules\Billing\Application\Subscriptions;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * A person's governing subscription, or the free plan (spec 009, US3).
 */
final class MySubscription
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
    ) {}

    /**
     * @return array{plan: array{id: string, name: string}, provider: ?string, status: ?string, current_period_start: ?string, current_period_end: ?string, grace_until: ?string, auto_renew: ?bool}
     */
    public function toWire(string $userId, string $locale): array
    {
        $governing = GoverningSubscription::among($this->subscriptions->forUser($userId), now()->toDateTimeImmutable());
        $plan = $governing === null
            ? $this->plans->defaultPlan()
            : ($this->plans->find($governing->planId) ?? throw new RuntimeException('A subscription points at a missing plan.'));

        return [
            'plan' => ['id' => $plan->id, 'name' => $plan->nameIn($locale)],
            'provider' => $governing?->provider,
            'status' => $governing?->status->value,
            'current_period_start' => self::instant($governing?->currentPeriodStart),
            'current_period_end' => self::instant($governing?->currentPeriodEnd),
            'grace_until' => self::instant($governing?->graceUntil),
            'auto_renew' => $governing?->autoRenew,
        ];
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
