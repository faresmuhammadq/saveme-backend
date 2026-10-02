<?php

namespace App\Modules\Budgeting\Http;

use App\Modules\Budgeting\Application\Sync\FeedPresenter;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Http\Resources\BudgetResource;

/**
 * Feed rows rendered by the same resource as the read endpoint.
 */
final class BudgetFeedPresenter implements FeedPresenter
{
    public function budget(BudgetRecord $budget): array
    {
        return BudgetResource::toArray($budget);
    }
}
