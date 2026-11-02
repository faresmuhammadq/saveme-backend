<?php

namespace App\Modules\Budgeting\Http\SharedBudgets;

use App\Modules\Budgeting\Application\Sync\SharedBudgetFeedPresenter;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetEntryResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetLineResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetMemberResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;

/**
 * Feed rows rendered by the same resources as the read endpoints.
 */
final class SharedBudgetFeedPresenterImpl implements SharedBudgetFeedPresenter
{
    public function budget(SharedBudgetRecord $budget): array
    {
        return SharedBudgetResource::toArray($budget);
    }

    public function member(SharedBudgetMemberRecord $member, ?string $displayName): array
    {
        return SharedBudgetMemberResource::toArray($member, $displayName);
    }

    public function line(SharedBudgetLineRecord $line): array
    {
        return SharedBudgetLineResource::toArray($line);
    }

    public function entry(SharedBudgetEntryRecord $entry): array
    {
        return SharedBudgetEntryResource::toArray($entry);
    }
}
