<?php

namespace App\Modules\Budgeting\Application\Export;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Budgeting's Shared Budget file of an account export (ADR-0027): the budgets
 * the person belongs to, and only the entries they wrote. Another member's
 * entries, names and notes are never in it. A wallet export carries none.
 */
final class SharedBudgetSection implements ExportSection
{
    private const PAGE = 500;

    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        if (! $target->isAccount()) {
            return;
        }

        $writer->declare('shared_budgets.json');

        foreach ($this->budgets->liveBudgetIdsOf($target->userId) as $budgetId) {
            $budget = $this->budgets->find($budgetId);
            $member = $this->budgets->activeMember($budgetId, $target->userId);

            if ($budget === null || $member === null) {
                continue;
            }

            $writer->append('shared_budgets.json', [
                'type' => 'shared_budget',
                'id' => $budget->id,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'limit_minor' => $budget->limitMinor,
                'role' => $member->role->value,
                'joined_at' => self::instant($member->createdAt),
            ]);
        }

        $after = '';

        do {
            $page = $this->budgets->entriesAuthoredBy($target->userId, $after, self::PAGE);

            foreach ($page as $entry) {
                $after = $entry->id;
                $writer->append('shared_budgets.json', $this->entry($entry));
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(SharedBudgetEntryRecord $entry): array
    {
        return [
            'type' => 'shared_budget_entry',
            'id' => $entry->id,
            'shared_budget_id' => $entry->sharedBudgetId,
            'category_id' => $entry->categoryId,
            'date' => $entry->date,
            'amount_minor' => $entry->amountMinor,
            'description' => $entry->description,
            'detached_at' => self::instant($entry->detachedAt),
            'deleted_at' => self::instant($entry->deletedAt),
        ];
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
