<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * `active` ⇄ `paused`; either → `ended`, which is final (spec 014, FR-005).
 */
enum RuleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
