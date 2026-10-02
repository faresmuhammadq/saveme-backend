<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Identity\Application\Events;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The plan demotion of spec 016 (R-11, P-09 rule 6): when the owner's plan
 * allows fewer members than a wallet has, every editor ranked beyond the
 * quota (owner first, then joining order) becomes a viewer, by the server.
 * Nobody is removed and nobody is promoted again. Idempotent by
 * construction: a second run finds no editor beyond the quota. A plan that
 * does not share at all leaves room for the owner only.
 */
final class EnforceMemberQuota
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly MembershipService $membership,
        private readonly MembershipQueryService $members,
    ) {}

    /**
     * Enforces the quota of every live wallet the person owns.
     *
     * @return array{wallets: int, demoted: int}
     */
    public function forOwner(string $ownerId): array
    {
        $demoted = 0;
        $walletIds = $this->members->ownedLiveWalletIds($ownerId);

        foreach ($walletIds as $walletId) {
            $demoted += $this->forWallet($walletId);
        }

        Log::info('sharing_quota_enforced', ['wallets' => count($walletIds), 'demoted' => $demoted]);

        return ['wallets' => count($walletIds), 'demoted' => $demoted];
    }

    /**
     * Enforces the quota of one wallet; returns how many editors became
     * viewers. A wallet that has gone (deleted or unowned) needs nothing.
     */
    public function forWallet(string $walletId): int
    {
        try {
            $entitlements = $this->entitlements->forWallet($walletId);
        } catch (RuntimeException) {
            return 0;
        }

        $quota = $entitlements->allows(Feature::SharingEnabled) ? $entitlements->quota(Feature::SharingMaxMembersPerWallet) : 1;

        try {
            $demoted = $this->membership->demoteBeyond($walletId, $quota);
        } catch (WalletNotFound) {
            return 0;
        }

        foreach ($demoted as $member) {
            event(new MemberRoleChanged(...Events::stamp(), walletId: $walletId, memberUserId: $member->userId, previousRole: 'editor', role: 'viewer', reason: 'plan'));
        }

        return count($demoted);
    }
}
