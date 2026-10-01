<?php

namespace App\Modules\Wallets\Domain\Membership;

use DateTimeImmutable;

/**
 * One person's membership of one wallet.
 */
final readonly class MemberRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public string $userId,
        public Role $role,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}
}
