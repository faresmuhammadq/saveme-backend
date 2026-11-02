<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;

/**
 * Renders Shared Budget feed rows as their read endpoints do. The Http layer
 * implements it; Application never imports a resource (P-02).
 */
interface SharedBudgetFeedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function budget(SharedBudgetRecord $budget): array;

    /**
     * @return array<string, mixed>
     */
    public function member(SharedBudgetMemberRecord $member, ?string $displayName): array;

    /**
     * @return array<string, mixed>
     */
    public function line(SharedBudgetLineRecord $line): array;

    /**
     * @return array<string, mixed>
     */
    public function entry(SharedBudgetEntryRecord $entry): array;
}
