<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use DateTimeImmutable;

/**
 * Every call to Google Play goes through this port (spec 024, FR-056,
 * ADR-0026). Adapters: `google`, `fake` (scripted; local and testing only) and
 * `disabled`. Purchase tokens are secrets: an adapter never logs one.
 */
interface PlayBillingGateway
{
    /**
     * Reads a subscription purchase (purchases.subscriptionsv2.get).
     */
    public function readSubscription(#[\SensitiveParameter] string $token): GatewayOutcome;

    /**
     * Reads a one-time product purchase (purchases.products.get).
     */
    public function readProduct(string $productId, #[\SensitiveParameter] string $token): GatewayOutcome;

    /**
     * Acknowledges a purchase. Google refunds an unacknowledged purchase after 3 days.
     */
    public function acknowledge(PlayProductKind $kind, string $productId, #[\SensitiveParameter] string $token): GatewayOutcome;

    /**
     * Lists purchases Google has voided since $since (voidedpurchases.list).
     */
    public function voidedSince(DateTimeImmutable $since, ?string $pageToken): VoidedPage;
}
