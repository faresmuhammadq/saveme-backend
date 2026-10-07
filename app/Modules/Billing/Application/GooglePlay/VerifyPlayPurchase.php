<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Application\Plans\PlanCatalogue;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\Refusals\PlayUnavailable;
use App\Modules\Billing\Domain\GooglePlay\Refusals\ProductNotMapped;
use App\Modules\Billing\Domain\GooglePlay\Refusals\PurchaseAlreadyLinked;
use App\Modules\Billing\Domain\GooglePlay\Refusals\PurchaseNotVerified;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;

/**
 * `POST /billing/google/purchases` (spec 024, US1, US2; R-6): verify one
 * purchase with Google, link it to the caller, record the subscription and
 * acknowledge it after commit. The caller never acknowledges and never sends
 * a price (P-10 rules 1 and 2; ADR-0026 rule 8).
 */
final class VerifyPlayPurchase
{
    public function __construct(
        private readonly PlanCatalogue $catalogue,
        private readonly ReconcilePlayPurchase $reconcile,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlayAnswers $answers,
    ) {}

    /**
     * @return array{purchase: array<string, mixed>, entitlements: array<string, mixed>, subscription: array<string, mixed>, pending: bool}
     *
     * @throws ProductNotMapped
     * @throws PurchaseAlreadyLinked
     * @throws PurchaseNotVerified
     * @throws PlayUnavailable
     */
    public function verify(string $userId, #[\SensitiveParameter] string $token, string $productId, string $locale): array
    {
        $verified = $this->check($userId, $token, $productId);

        return [
            'purchase' => $verified->toWire(),
            ...$this->answers->for($userId, $locale),
            'pending' => $verified->isPending(),
        ];
    }

    /**
     * Verifies and links without building the wire answer (shared with restore).
     *
     * @throws ProductNotMapped
     * @throws PurchaseAlreadyLinked
     * @throws PurchaseNotVerified
     * @throws PlayUnavailable
     */
    public function check(string $userId, #[\SensitiveParameter] string $token, string $productId): VerifiedPurchase
    {
        $plan = $this->catalogue->planForPlayProduct($productId) ?? throw new ProductNotMapped;
        $kind = $plan->periodType === 'lifetime' ? PlayProductKind::OneTime : PlayProductKind::Subscription;

        $outcome = $this->reconcile->reconcile($token, $kind, $productId, $userId);

        switch ($outcome->kind) {
            case SyncOutcome::ELSEWHERE:
            case SyncOutcome::PURGED:
                throw new PurchaseAlreadyLinked;
            case SyncOutcome::UNAVAILABLE:
                throw new PlayUnavailable($outcome->retryAfterSeconds);
            case SyncOutcome::SYNCED:
                break;
            default:
                throw new PurchaseNotVerified;
        }

        $record = $outcome->record ?? throw new PurchaseNotVerified;
        $subscription = $outcome->subscriptionId === null ? null : $this->subscriptions->find($outcome->subscriptionId);

        return new VerifiedPurchase(
            purchaseId: (string) $outcome->purchaseId,
            productId: $record->productId,
            state: $record->state->value,
            acknowledged: $outcome->acknowledged,
            autoRenew: $subscription->autoRenew ?? $record->autoRenew,
            currentPeriodEnd: $subscription !== null ? $subscription->currentPeriodEnd : $record->expiresAt,
            test: $record->isTest,
            newlyLinked: $outcome->newlyLinked,
        );
    }
}
