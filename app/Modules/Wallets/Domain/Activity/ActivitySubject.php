<?php

namespace App\Modules\Wallets\Domain\Activity;

/**
 * The kind of record an activity entry is about; the entry holds its id only.
 */
enum ActivitySubject: string
{
    case Wallet = 'wallet';
    case Transaction = 'transaction';
    case RecurringRule = 'recurring_rule';
    case Occurrence = 'occurrence';
    case Member = 'member';
    case Invitation = 'invitation';
}
