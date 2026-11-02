<?php

namespace App\Modules\Billing\Domain;

/**
 * The capabilities a plan can grant (P-09 rule 2; ADR-0013 rule 6). Every
 * capability check in every module names one of these; adding a capability
 * means adding a case and plan data, never a condition on a plan.
 */
enum Feature: string
{
    case SharingEnabled = 'sharing.enabled';
    case SharingMaxMembersPerWallet = 'sharing.max_members_per_wallet';
    case SharingMaxMembersPerBudget = 'sharing.max_members_per_budget';
    case ExportEnabled = 'export.enabled';
    case ReportsAdvanced = 'reports.advanced';
    case WalletsMax = 'wallets.max';

    /**
     * A quota carries a number (null = unlimited); anything else is a yes/no flag.
     */
    public function isQuota(): bool
    {
        return match ($this) {
            self::SharingMaxMembersPerWallet, self::SharingMaxMembersPerBudget, self::WalletsMax => true,
            default => false,
        };
    }

    /**
     * The value a plan that does not grant this capability has.
     */
    public function deniedValue(): bool|int
    {
        return $this->isQuota() ? 0 : false;
    }
}
