<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\BudgetRecord;

/**
 * How a Budgeting feed row renders. Application cannot reach Http, so the
 * Http layer implements this with the resource the read endpoints use.
 */
interface FeedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function budget(BudgetRecord $budget): array;
}
