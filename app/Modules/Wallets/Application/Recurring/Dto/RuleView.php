<?php

namespace App\Modules\Wallets\Application\Recurring\Dto;

use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;

/**
 * A recurring rule as a read presents it.
 */
final readonly class RuleView
{
    public function __construct(public RecurringRuleRecord $rule) {}
}
