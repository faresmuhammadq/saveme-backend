<?php

namespace App\Modules\Billing\Application\Subscriptions;

/**
 * A person's subscriptions, newest first, and which one governs (spec 021,
 * FR-021).
 */
final readonly class AccessView
{
    /**
     * @param  list<SubscriptionView>  $subscriptions
     */
    public function __construct(
        public ?string $governingSubscriptionId,
        public array $subscriptions,
    ) {}

    /**
     * @return array{governing_subscription_id: ?string, subscriptions: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'governing_subscription_id' => $this->governingSubscriptionId,
            'subscriptions' => array_map(fn (SubscriptionView $subscription): array => $subscription->toArray(), $this->subscriptions),
        ];
    }
}
