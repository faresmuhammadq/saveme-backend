<?php

namespace App\Modules\Billing\Application\RedeemCodes;

use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily sweep (spec 020, FR-022a): issued codes past their 30 days become
 * `expired` and their claims close, so the 90-day erasure clock starts from a
 * recorded fact. Redemption checks the expiry itself, so the sweep's timing
 * never extends a code. Idempotent.
 */
final class ExpireRedeemCodes
{
    public function __construct(
        private readonly RedeemCodeRepository $codes,
        private readonly ClaimRepository $claims,
    ) {}

    /**
     * @return int codes expired
     */
    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();

        $claimIds = DB::transaction(function () use ($now): array {
            $claimIds = $this->codes->expireDue($now);

            foreach ($claimIds as $claimId) {
                $this->claims->close($claimId, $now);
            }

            return $claimIds;
        });

        Log::info('billing_redeem_codes_expired', ['expired' => count($claimIds)]);

        return count($claimIds);
    }
}
