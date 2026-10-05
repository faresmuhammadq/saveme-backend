<?php

namespace App\Modules\Budgeting\Application\Export;

use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Budgeting's file of an account export (spec 022, FR-002): the person's own
 * budgets, live and tombstoned — category, currency, limit, period type,
 * `archived_at` — and no progress. A wallet export carries none.
 */
final class BudgetSection implements ExportSection
{
    private const PAGE = 500;

    public function __construct(private readonly BudgetRepository $budgets) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        if (! $target->isAccount()) {
            return;
        }

        $writer->declare('budgets.json');
        $after = 0;

        do {
            $page = $this->budgets->changedFor($target->userId, $after, self::PAGE);

            foreach ($page as $budget) {
                $after = max($after, $budget->changeSeq);
                $writer->append('budgets.json', $this->record($budget));
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(BudgetRecord $budget): array
    {
        if ($budget->isTombstoned()) {
            return ['id' => $budget->id, 'deleted_at' => self::instant($budget->deletedAt)];
        }

        return [
            'id' => $budget->id,
            'category_id' => $budget->categoryId,
            'currency' => $budget->currency,
            'amount_minor' => $budget->amountMinor,
            'period_type' => $budget->periodType->value,
            'archived_at' => self::instant($budget->archivedAt),
            'created_at' => self::instant($budget->createdAt),
            'updated_at' => self::instant($budget->updatedAt),
        ];
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
