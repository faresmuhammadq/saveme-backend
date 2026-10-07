<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Subscriptions\MySubscription;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;

/**
 * The `entitlements` and `subscription` members of a Play answer, from the
 * same builders as `/me/entitlements` and `/me/subscription`, so the shapes
 * cannot drift (spec 024, FR-011, FR-017).
 */
final class PlayAnswers
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly MySubscription $mySubscription,
    ) {}

    /**
     * @return array{entitlements: array<string, mixed>, subscription: array<string, mixed>}
     */
    public function for(string $userId, string $locale): array
    {
        // A fresh resolver: nothing resolved earlier in this request may colour the answer.
        $entitlements = new EntitlementService($this->subscriptions, $this->plans);

        return [
            'entitlements' => $entitlements->summaryFor($userId)->toWire($locale),
            'subscription' => $this->mySubscription->toWire($userId, $locale),
        ];
    }
}
