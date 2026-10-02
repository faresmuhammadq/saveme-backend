<?php

namespace App\Modules\Budgeting\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A write carried a stale version, or targeted a tombstone. Nothing was
 * applied; `current` carries the stored budget as a read renders it (P-07).
 */
final class BudgetVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the budget as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}
