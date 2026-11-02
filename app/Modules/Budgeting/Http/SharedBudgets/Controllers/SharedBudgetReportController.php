<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Controllers;

use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetReports;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\SharedBudgetRange;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetProgressResource;
use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SharedBudgetReportController
{
    public function summary(Request $request, string $id, SharedBudgetReports $reports, DisplayNames $names): JsonResponse
    {
        $budgetId = BudgetId::assert($id);
        [$from, $to] = SharedBudgetRange::from($request);

        $report = $reports->summary($this->callerId($request), $budgetId, $from, $to);
        $displayNames = $names->of(array_keys($report['byMember']));

        return Envelope::ok([
            'shared_budget_id' => $budgetId,
            'from' => $from,
            'to' => $to,
            'currency' => $report['budget']->currency,
            'spent' => SharedBudgetProgressResource::money($report['spent']),
            'by_category' => array_map(fn (string $categoryId, Money $spent): array => ['category_id' => $categoryId, 'spent' => SharedBudgetProgressResource::money($spent)], array_keys($report['byCategory']), array_values($report['byCategory'])),
            'by_member' => array_map(fn (string $userId, Money $spent): array => ['user_id' => $userId, 'display_name' => $displayNames[$userId] ?? null, 'spent' => SharedBudgetProgressResource::money($spent)], array_keys($report['byMember']), array_values($report['byMember'])),
        ]);
    }

    public function trend(Request $request, string $id, SharedBudgetReports $reports): JsonResponse
    {
        $budgetId = BudgetId::assert($id);
        [$from, $to] = SharedBudgetRange::from($request);

        $rows = $reports->trend($this->callerId($request), $budgetId, $from, $to) ?? throw new ApiRefusal(ErrorCode::InvalidQueryParameter);

        return Envelope::ok([
            'shared_budget_id' => $budgetId,
            'periods' => array_map(fn (array $row): array => [
                'start' => $row['period']->start,
                'end' => $row['period']->end,
                'spent' => SharedBudgetProgressResource::money($row['spent']),
                'limit' => $row['limit'] === null ? null : SharedBudgetProgressResource::money($row['limit']),
            ], $rows),
        ]);
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
