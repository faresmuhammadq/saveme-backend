<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ReceiptStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily retention run (spec 020, FR-039, R-11): 90 days after a claim
 * closes its address, lookup value and receipt are erased (the file first, so
 * a failure leaves a row to retry rather than a file nobody points at); 400
 * days after, the claim and its code record are deleted. Idempotent.
 */
final class PurgeClaims
{
    public const BATCH = 200;

    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly ReceiptStore $receipts,
    ) {}

    /**
     * @return array{erased: int, deleted: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= now()->toDateTimeImmutable();
        $eraseBefore = $now->modify('-'.(int) config('billing.instapay.erase_after_days').' days');
        $deleteBefore = $now->modify('-'.(int) config('billing.instapay.delete_after_days').' days');
        $erased = 0;

        do {
            $batch = $this->claims->erasableClosedBefore($eraseBefore, self::BATCH);

            foreach ($batch as $claim) {
                if ($claim->receiptPath !== null) {
                    $this->receipts->delete($claim->receiptPath);
                }

                DB::transaction(fn () => $this->claims->markErased($claim->id, $now));
                $erased++;
            }
        } while (count($batch) === self::BATCH);

        $deleted = $this->claims->deleteClosedBefore($deleteBefore);

        Log::info('billing_claims_purged', ['erased' => $erased, 'deleted' => $deleted]);

        return ['erased' => $erased, 'deleted' => $deleted];
    }
}
