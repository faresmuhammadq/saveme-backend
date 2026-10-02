<?php

namespace App\Modules\Wallets\Application\Recurring\Dto;

/**
 * The outcome of a rule save: the rule as a read presents it, and whether
 * this call created it (201) or not (200).
 */
final readonly class SavedRecurringRule
{
    public function __construct(
        public RuleView $view,
        public bool $created,
    ) {}
}
