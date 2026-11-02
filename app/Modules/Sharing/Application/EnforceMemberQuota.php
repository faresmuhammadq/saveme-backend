<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetDirectory;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
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
        private readonly SharedBudgetDirectory $sharedBudgets,
        private readonly SharedBudgetService $budgetService,
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

        $demoted += $this->forOwnedSharedBudgets($ownerId);

        Log::info('sharing_quota_enforced', ['wallets' => count($walletIds), 'demoted' => $demoted]);

        return ['wallets' => count($walletIds), 'demoted' => $demoted];
    }

    /**
     * Enforces the seat quota of every Shared Budget the person owns (ADR-0027
     * rule 14); returns how many members became read-only. A plan that does
     * not share leaves room for the owner only.
     */
    private function forOwnedSharedBudgets(string $ownerId): int
    {
        $entitlements = $this->entitlements->forUser($ownerId);
        $quota = $entitlements->allows(Feature::SharingEnabled) ? $entitlements->quota(Feature::SharingMaxMembersPerBudget) : 1;
        $limited = 0;

        foreach ($this->sharedBudgets->ownedLiveBudgetIds($ownerId) as $budgetId) {
            $limited += count($this->budgetService->limitMembersTo($budgetId, $quota));
        }

        return $limited;
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
