<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

/**
 * A person's role in a Shared Budget (ADR-0027 rule 3): only the owner
 * changes the budget; a member reads it and adds their own transactions.
 */
enum SharedBudgetRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
