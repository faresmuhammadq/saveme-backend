<?php

namespace App\Modules\Wallets\Application\Membership;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-sends a person's memberships after a display-name change, one run of at
 * most 50 wallets (ADR-0021). Unique until processing: a second rename that
 * arrives while this run still waits is covered by it (names are rendered at
 * read), and one that arrives once it has started queues a fresh run. A run
 * that leaves wallets behind dispatches its own continuation. Idempotent: a
 * retry only re-stamps again.
 */
final class RestampMemberNames implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly string $userId,
        public readonly string $afterWalletId = '',
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.'|'.$this->afterWalletId;
    }

    public function handle(MembershipService $memberships): void
    {
        $continuation = $memberships->restampNames($this->userId, $this->afterWalletId);

        if ($continuation !== null) {
            self::dispatch($this->userId, $continuation);
        }
    }
}
