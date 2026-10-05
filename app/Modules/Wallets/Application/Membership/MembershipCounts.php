<?php

namespace App\Modules\Wallets\Application\Membership;

use App\Modules\Wallets\Domain\Ports\MembershipRepository;

/**
 * What the back office may ask Wallets about membership: counts of live rows,
 * never a wallet name or an amount (spec 021, R-10).
 */
final class MembershipCounts
{
    public function __construct(private readonly MembershipRepository $members) {}

    public function forUser(string $userId): MemberCounts
    {
        $counts = $this->members->countsFor($userId);

        return new MemberCounts($counts['owned'], $counts['joined'], $counts['others']);
    }

    /**
     * Live wallets with at least two live members.
     */
    public function sharedWallets(): int
    {
        return $this->members->countSharedWallets();
    }
}
