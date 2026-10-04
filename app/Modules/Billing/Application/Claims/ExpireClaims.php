<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Application\Jobs\SendClaimExpiredMail;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The hourly sweep (spec 020, FR-016, R-11): closes submitted claims past
 * their 7 days as `expired` and queues one notice mail each, in batches of
 * 200. The update is conditional on the status, so a second run changes
 * nothing and queues nothing. Review also refuses a claim past its expiry,
 * so the sweep's timing is no security matter.
 */
final class ExpireClaims
{
    public const BATCH = 200;

    public function __construct(private readonly ClaimRepository $claims) {}

    /**
     * @return int claims expired
     */
    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();
        $expired = 0;

        do {
            $ids = DB::transaction(function () use ($now): array {
                $ids = $this->claims->expireDue($now, self::BATCH);

                foreach ($ids as $id) {
                    SendClaimExpiredMail::dispatch($id)->afterCommit();
                }

                return $ids;
            });

            $expired += count($ids);
        } while (count($ids) === self::BATCH);

        Log::info('billing_claims_expired', ['expired' => $expired]);

        return $expired;
    }
}
