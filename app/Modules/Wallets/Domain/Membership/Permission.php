<?php

namespace App\Modules\Wallets\Domain\Membership;

/**
 * One case per row of the permission matrix in spec 010 section 6, named by
 * the row's ID. Later specs ask the authorizer about their row and never
 * compare a role themselves (P-08).
 */
enum Permission: string
{
    case W01ReadWallet = 'W-01';
    case W02EditWallet = 'W-02';
    case W03Archive = 'W-03';
    case W04Delete = 'W-04';
    case W05TransferOwnership = 'W-05';
    case T01ReadTransactions = 'T-01';
    case T02WriteTransactions = 'T-02';
    case T03Transfer = 'T-03';
    case R01ReadRecurring = 'R-01';
    case R02WriteRecurring = 'R-02';
    case S01SeeInvitations = 'S-01';
    case S02SendInvitation = 'S-02';
    case S03ChangeRole = 'S-03';
    case S04RemoveMember = 'S-04';
    case S05Leave = 'S-05';
    case S06RevokeInvitation = 'S-06';
    case S07ReadActivity = 'S-07';

    /**
     * The matrix row ID, e.g. W-02.
     */
    public function rowId(): string
    {
        return $this->value;
    }
}
