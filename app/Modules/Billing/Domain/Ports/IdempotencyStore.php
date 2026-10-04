<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\Idempotency\IdempotencyOutcome;
use DateTimeImmutable;

/**
 * The stored answers for Idempotency-Key (spec 020, R-2).
 */
interface IdempotencyStore
{
    /**
     * Claims the key for a new request, or reports what already holds it. A
     * request still `in_progress` after 60 seconds is abandoned: a retry with
     * the same fingerprint takes it over.
     */
    public function begin(string $scope, string $key, string $fingerprint, DateTimeImmutable $now): IdempotencyOutcome;

    /**
     * @param  string  $body  the answer as JSON text
     */
    public function complete(string $scope, string $key, int $status, string $body): void;

    /**
     * Releases the key so a corrected request may use it again.
     */
    public function abandon(string $scope, string $key): void;

    /**
     * @return int records deleted
     */
    public function purgeExpired(DateTimeImmutable $now): int;
}
