<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Domain\BudgetRecord;
use RuntimeException;

/**
 * Leaves a write's transaction when, under the locks, the write turns out to
 * change nothing: the rollback gives the owner's stamp back (spec 013,
 * default 26). Caught by BudgetService; never escapes it.
 */
final class Replay extends RuntimeException
{
    public function __construct(public readonly BudgetRecord $record)
    {
        parent::__construct('The write changes nothing.');
    }
}
