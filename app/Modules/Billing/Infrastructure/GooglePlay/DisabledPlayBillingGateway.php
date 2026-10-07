<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\VoidedPage;
use DateTimeImmutable;

/**
 * The default adapter: Google Play is not configured, so nothing is verified
 * and nothing is granted (spec 024, FR-057). Every call is `Unavailable`.
 */
final class DisabledPlayBillingGateway implements PlayBillingGateway
{
    public function readSubscription(#[\SensitiveParameter] string $token): GatewayOutcome
    {
        return GatewayOutcome::unavailable(3600);
    }

    public function readProduct(string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        return GatewayOutcome::unavailable(3600);
    }

    public function acknowledge(PlayProductKind $kind, string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        return GatewayOutcome::unavailable(3600);
    }

    public function voidedSince(DateTimeImmutable $since, ?string $pageToken): VoidedPage
    {
        return VoidedPage::unavailable();
    }
}
