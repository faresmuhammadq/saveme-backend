<?php

namespace App\Modules\Billing\Application\Idempotency;

use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use DateTimeImmutable;

/**
 * The hourly purge: idempotency records live 24 hours (spec 020, R-2, FR-039).
 */
final class PurgeIdempotencyRecords
{
    public function __construct(private readonly IdempotencyStore $store) {}

    /**
     * @return int records deleted
     */
    public function run(?DateTimeImmutable $now = null): int
    {
        return $this->store->purgeExpired($now ?? now()->toDateTimeImmutable());
    }
}
