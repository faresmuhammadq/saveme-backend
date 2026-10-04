<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\ReceiptStore;
use Illuminate\Support\Facades\URL;

/**
 * Receipt viewing for operators (spec 020, FR-014, R-10). The link is signed
 * and lives 5 minutes; the route that serves it belongs to Admin (P-18 rule
 * 4) and calls `open`, which decrypts. An erased claim, or one without a
 * receipt, has none.
 */
final class ClaimReceipts
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ReceiptStore $store,
        private readonly ClaimSealer $sealer,
    ) {}

    public function temporaryUrl(string $claimId): ?string
    {
        $claim = $this->claims->find($claimId);

        if ($claim === null || $claim->receiptPath === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.claims.receipt',
            now()->addMinutes((int) config('billing.instapay.receipt_link_minutes')),
            ['claim' => $claim->id],
        );
    }

    /**
     * @return array{media_type: string, bytes: string}|null
     */
    public function open(string $claimId): ?array
    {
        $claim = $this->claims->find($claimId);

        if ($claim === null || $claim->receiptPath === null || $claim->receiptMediaType === null) {
            return null;
        }

        $sealed = $this->store->get($claim->receiptPath);

        if ($sealed === null) {
            return null;
        }

        return ['media_type' => $claim->receiptMediaType, 'bytes' => $this->sealer->open($sealed, $claim->id, 'receipt')];
    }
}
