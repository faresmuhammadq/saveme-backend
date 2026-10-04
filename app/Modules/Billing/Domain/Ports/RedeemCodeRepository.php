<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\RedeemCodes\RedeemCodeRecord;
use DateTimeImmutable;

interface RedeemCodeRepository
{
    public function insert(string $claimId, string $codeHash, DateTimeImmutable $expiresAt): RedeemCodeRecord;

    public function lockByHash(string $codeHash): ?RedeemCodeRecord;

    public function lockForClaim(string $claimId): ?RedeemCodeRecord;

    public function forClaim(string $claimId): ?RedeemCodeRecord;

    public function markRedeemed(string $id, string $userId, string $subscriptionId, DateTimeImmutable $now): void;

    public function markRevoked(string $id, DateTimeImmutable $now): void;

    /**
     * Expires issued codes past `expires_at`.
     *
     * @return list<string> ids of the claims whose codes this call expired
     */
    public function expireDue(DateTimeImmutable $now): array;
}
