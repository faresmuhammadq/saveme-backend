<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * The stored states only. "Due" and "missed" are derived by readers and never
 * stored (ADR-0018 rule 8).
 */
enum OccurrenceStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Skipped = 'skipped';
}
