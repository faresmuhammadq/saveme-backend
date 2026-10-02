<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use Closure;
use DateTimeImmutable;

/**
 * A deleted wallet's category references are tombstoned with it, in the same
 * unit of work (spec 011, FR-025). The categories themselves are untouched:
 * they belong to people, not wallets.
 */
final class ReferencesGoWithWallet implements WalletDeletionParticipant
{
    public function __construct(private readonly CategoryReferenceRepository $references) {}

    public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
    {
        $this->references->tombstoneAllInWallet($walletId, $at, $nextChangeSeq);
    }
}
