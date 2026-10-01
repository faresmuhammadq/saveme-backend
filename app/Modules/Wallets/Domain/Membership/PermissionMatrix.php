<?php

namespace App\Modules\Wallets\Domain\Membership;

use App\Modules\Billing\Domain\Feature;

/**
 * The permission matrix of spec 010 section 6, once, as data (P-08 rule 5).
 * tests/Unit/Wallets/PermissionMatrixTest.php compares it with the spec row
 * by row, so a change here without the spec fails.
 */
final class PermissionMatrix
{
    public static function allows(Role $role, Permission $permission): bool
    {
        return in_array($role, self::rolesFor($permission), true);
    }

    /**
     * The capability the wallet owner's plan must also grant, if the row names one.
     */
    public static function capabilityFor(Permission $permission): ?Feature
    {
        return match ($permission) {
            Permission::S02SendOrRevokeInvitation => Feature::SharingEnabled,
            default => null,
        };
    }

    /**
     * @return list<Role>
     */
    private static function rolesFor(Permission $permission): array
    {
        return match ($permission) {
            Permission::W01ReadWallet,
            Permission::T01ReadTransactions,
            Permission::R01ReadRecurring,
            Permission::S01SeeInvitations => [Role::Owner, Role::Editor, Role::Viewer],
            Permission::T02WriteTransactions,
            Permission::T03Transfer,
            Permission::R02WriteRecurring => [Role::Owner, Role::Editor],
            Permission::W02EditWallet,
            Permission::W03Archive,
            Permission::W04Delete,
            Permission::W05TransferOwnership,
            Permission::S02SendOrRevokeInvitation,
            Permission::S03ChangeRole,
            Permission::S04RemoveMember => [Role::Owner],
            Permission::S05Leave => [Role::Editor, Role::Viewer],
        };
    }
}
