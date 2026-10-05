<?php

namespace App\Modules\Wallets\Application\Departure;

use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;

/**
 * Wallets' leaving step of an account purge (spec 022, FR-044, FR-054):
 * every remaining active membership of the departing person is ended
 * exactly as leaving ends it — a tombstone stamped from the wallet's counter
 * and the person's user counter (ADR-0017), one activity entry, one
 * `membership_revoked` audit entry — each in its own unit of work. Their
 * entries in those wallets are untouched. A no-op once none remains.
 */
final class LeaveRemainingWallets implements AccountPurgeParticipant
{
    public function __construct(
        private readonly MembershipRepository $members,
        private readonly MembershipService $memberships,
    ) {}

    public function step(): PurgeStep
    {
        return PurgeStep::LeaveWallets;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        $left = 0;

        foreach ($this->walletIds($userId) as $walletId) {
            try {
                $this->memberships->leave($walletId, $userId);
                $left++;
            } catch (WalletNotFound) {
                // Already ended, or the wallet is gone: nothing to do.
            }
        }

        return PurgeCounts::of('memberships_ended', $left);
    }

    /**
     * @return list<string>
     */
    private function walletIds(string $userId): array
    {
        $ids = [];
        $after = '';

        do {
            $page = $this->members->liveWalletIdsOf($userId, $after, 200);
            array_push($ids, ...$page);
            $after = $page === [] ? '' : $page[array_key_last($page)];
        } while (count($page) === 200);

        return $ids;
    }
}
