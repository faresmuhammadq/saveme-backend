<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Recurring\Ports\StampBlock;

/**
 * The change_seq of every rule or occurrence a recurring write changes, taken
 * from the rule's wallet counter under the wallet lock, and the wallet's own
 * final stamp, the highest in its scope (spec 012 default 18, spec 010 R-3).
 * Writes made by the server stamp exactly as a person's do (FR-023).
 */
final class RecurringStamps
{
    public function __construct(
        private readonly ScopeSequence $sequence,
        private readonly StampBlock $block,
    ) {}

    public function next(string $walletId): int
    {
        return $this->sequence->nextFor($walletId);
    }

    /**
     * The first of $count consecutive stamps.
     */
    public function block(string $walletId, int $count): int
    {
        return $this->block->reserve($walletId, $count);
    }

    /**
     * The wallet's final stamp of the write.
     */
    public function finish(string $walletId): void
    {
        $this->sequence->nextFor($walletId);
    }
}
