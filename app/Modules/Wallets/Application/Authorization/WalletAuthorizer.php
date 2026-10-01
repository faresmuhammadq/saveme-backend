<?php

namespace App\Modules\Wallets\Application\Authorization;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\PermissionMatrix;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;

/**
 * The one place a wallet permission is decided (P-08, spec 010 section 6).
 * Later specs call authorize() with their matrix row and never compare a
 * role themselves.
 */
final class WalletAuthorizer
{
    public function __construct(
        private readonly MembershipRepository $members,
        private readonly WalletRepository $wallets,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The caller's role in the wallet: their active role, or, for a wallet
     * deleted since, the role they held when it was deleted. Anyone else
     * gets not-found, whether the wallet exists or not (ADR-0006).
     *
     * @throws WalletNotFound
     */
    public function memberRole(string $userId, string $walletId): Role
    {
        $active = $this->members->activeFor($walletId, $userId);

        if ($active !== null) {
            return $active->role;
        }

        $deletedAt = $this->wallets->find($walletId)?->deletedAt;
        $atDeletion = $deletedAt === null ? null : $this->members->atDeletion($walletId, $userId, $deletedAt);

        return ($atDeletion ?? throw new WalletNotFound)->role;
    }

    /**
     * Allows the action or refuses it: not-found for a non-member, forbidden
     * for a role the row does not allow, not-entitled when the row names a
     * capability the wallet owner's plan lacks.
     *
     * @throws WalletNotFound
     * @throws WalletActionForbidden
     * @throws FeatureNotEntitled
     */
    public function authorize(string $userId, string $walletId, Permission $permission): Role
    {
        $role = $this->memberRole($userId, $walletId);

        if (! PermissionMatrix::allows($role, $permission)) {
            throw new WalletActionForbidden;
        }

        $capability = PermissionMatrix::capabilityFor($permission);

        if ($capability !== null && ! $this->entitlements->forWallet($walletId)->allows($capability)) {
            throw new FeatureNotEntitled;
        }

        return $role;
    }
}
