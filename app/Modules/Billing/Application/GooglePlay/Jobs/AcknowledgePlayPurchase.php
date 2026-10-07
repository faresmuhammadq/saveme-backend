<?php

namespace App\Modules\Billing\Application\GooglePlay\Jobs;

use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Retries the acknowledgement Google refunds after 3 days (spec 024, FR-014):
 * only for a linked purchase Google reports paid, never for an unlinked one.
 * Carries the Play Purchase id only (FR-055). Five attempts with backoff;
 * the hourly pass picks up whatever is still unacknowledged.
 */
final class AcknowledgePlayPurchase implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $purchaseId)
    {
        $this->onQueue('billing');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return $this->purchaseId;
    }

    public function handle(PlayPurchaseRepository $purchases, PlayBillingGateway $gateway, PlayTokenSealer $sealer): void
    {
        $purchase = $purchases->find($this->purchaseId);

        if ($purchase === null || ! $purchase->isLinked() || $purchase->acknowledgedAt !== null || $purchase->tokenSealed === null || ! $purchase->playState->isPaid()) {
            return;
        }

        $outcome = $gateway->acknowledge($purchase->kind, $purchase->productId, $sealer->open($purchase->tokenSealed, 'play_purchase', $purchase->id));

        if ($outcome->isAcknowledgedAtGoogle()) {
            $purchases->update($purchase->id, ['acknowledged_at' => now()->toDateTimeImmutable()]);

            return;
        }

        throw new RuntimeException('Google Play could not acknowledge the purchase yet.');
    }
}
