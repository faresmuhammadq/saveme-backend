<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Identity\Application\Events;
use App\Modules\Sharing\Domain\Events\MemberRevoked;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use App\Modules\Sharing\Domain\MemberQuota;
use App\Modules\Sharing\Domain\Refusals\MembershipVersionConflict;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Application\Membership\MembershipService;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\ScopeWrite;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\Refusals\OwnerMembershipImmutable;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use Closure;

/**
 * The owner's control of the people in a wallet, and a member's own exit
 * (spec 016, FR-015 to FR-020): change a role between editor and viewer,
 * remove a member, leave. Each write is one unit of work under the wallet
 * lock; the checks run under it, and the write itself is Wallets'
 * MembershipService (both stamps, audit entry, activity entry). Nothing is
 * deleted and nothing a member recorded is reassigned (R-15).
 *
 * Precedence follows spec section 6 per operation. The one deliberate
 * reordering: a role change that names the owner (as target or as the new
 * role) is refused as IMMUTABLE_FIELD at `/role` as soon as the caller is
 * known to be the owner, before the plan check and the version check.
 *
 * Raising a viewer to editor needs the owner's sharing capability and the
 * wallet within its member quota (the S-03 amendment of spec 010); lowering,
 * removal and leaving are never gated.
 */
final class MemberService
{
    public function __construct(
        private readonly MembershipService $membership,
        private readonly MembershipQueryService $members,
        private readonly WalletScopeWrites $scopes,
        private readonly WalletAuthorizer $authorizer,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  Closure(MemberView): array<string, mixed>  $present  how the member list renders a membership, for a conflict's `current`
     *
     * @throws WalletNotFound
     * @throws WalletActionForbidden
     * @throws FeatureNotEntitled
     * @throws OwnerMembershipImmutable
     * @throws MembershipVersionConflict
     */
    public function changeRole(string $actorId, string $walletId, string $memberUserId, Role $role, int $version, Closure $present): MemberView
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($actorId, $walletId, $memberUserId, $role, $version, $present): MemberView {
            $this->assertLive($scope);

            $this->authorizer->memberRole($actorId, $walletId);
            $target = $this->members->activeMember($walletId, $memberUserId) ?? throw new WalletNotFound;
            $this->authorizer->authorize($actorId, $walletId, Permission::S03ChangeRole);

            if ($target->role === Role::Owner || $role === Role::Owner) {
                throw new OwnerMembershipImmutable;
            }

            if ($target->role === Role::Viewer && $role === Role::Editor) {
                $this->assertMayRaise($walletId);
            }

            if ($target->role === $role) {
                return $this->members->view($target);
            }

            if ($version !== $target->version) {
                throw new MembershipVersionConflict($present($this->members->view($target)));
            }

            $changed = $this->membership->changeRole($walletId, $actorId, $memberUserId, $role);

            event(new MemberRoleChanged(...Events::stamp(), walletId: $walletId, memberUserId: $memberUserId, previousRole: $target->role->value, role: $role->value, reason: 'owner', actorUserId: $actorId));

            return $this->members->view($changed);
        });
    }

    /**
     * @param  Closure(MemberView): array<string, mixed>  $present
     *
     * @throws WalletNotFound
     * @throws WalletActionForbidden
     * @throws MembershipVersionConflict
     */
    public function remove(string $actorId, string $walletId, string $memberUserId, int $version, Closure $present): MemberView
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($actorId, $walletId, $memberUserId, $version, $present): MemberView {
            $this->assertLive($scope);

            $this->authorizer->memberRole($actorId, $walletId);
            $latest = $this->members->latestMember($walletId, $memberUserId) ?? throw new WalletNotFound;
            $this->authorizer->authorize($actorId, $walletId, Permission::S04RemoveMember);

            if ($memberUserId === $actorId) {
                throw new WalletActionForbidden;
            }

            if ($latest->deletedAt !== null) {
                return $this->members->view($latest);
            }

            if ($version !== $latest->version) {
                throw new MembershipVersionConflict($present($this->members->view($latest)));
            }

            $removed = $this->membership->remove($walletId, $actorId, $memberUserId);

            event(new MemberRevoked(...Events::stamp(), walletId: $walletId, memberUserId: $memberUserId, actorUserId: $actorId, reason: 'removed'));

            return $this->members->view($removed);
        });
    }

    /**
     * The caller leaves. Open to an unverified account; a repeated leave is
     * not-found because the caller is no longer a member.
     *
     * @param  Closure(MemberView): array<string, mixed>  $present
     *
     * @throws WalletNotFound
     * @throws WalletActionForbidden for the owner
     * @throws MembershipVersionConflict
     */
    public function leave(string $actorId, string $walletId, int $version, Closure $present): MemberView
    {
        return $this->scopes->within($walletId, function (ScopeWrite $scope) use ($actorId, $walletId, $version, $present): MemberView {
            $this->assertLive($scope);

            $own = $this->members->activeMember($walletId, $actorId) ?? throw new WalletNotFound;
            $this->authorizer->authorize($actorId, $walletId, Permission::S05Leave);

            if ($version !== $own->version) {
                throw new MembershipVersionConflict($present($this->members->view($own)));
            }

            $left = $this->membership->leave($walletId, $actorId);

            event(new MemberRevoked(...Events::stamp(), walletId: $walletId, memberUserId: $actorId, actorUserId: $actorId, reason: 'left'));

            return $this->members->view($left);
        });
    }

    /**
     * A deleted wallet has no membership left to act on.
     */
    private function assertLive(ScopeWrite $scope): void
    {
        if ($scope->wallet->isTombstone()) {
            throw new WalletNotFound;
        }
    }

    /**
     * Raising to editor: the owner's plan must share and the wallet must not
     * hold more people than the quota (a downgrade is not undone by raising
     * past it).
     *
     * @throws FeatureNotEntitled
     */
    private function assertMayRaise(string $walletId): void
    {
        $entitlements = $this->entitlements->forWallet($walletId);

        if (! $entitlements->allows(Feature::SharingEnabled)) {
            throw new FeatureNotEntitled(Feature::SharingEnabled);
        }

        $fits = MemberQuota::canPromote(
            $this->members->countActive($walletId),
            $entitlements->quota(Feature::SharingMaxMembersPerWallet),
            true,
        );

        if (! $fits) {
            throw new FeatureNotEntitled(Feature::SharingMaxMembersPerWallet);
        }
    }
}
