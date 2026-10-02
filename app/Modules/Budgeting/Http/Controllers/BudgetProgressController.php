<?php

namespace App\Modules\Budgeting\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Domain\BudgetProgress;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\Requests\BudgetProgressRequest;
use App\Modules\Budgeting\Http\Resources\BudgetProgressResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BudgetProgressController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, BudgetQueryService $budgets): JsonResponse
    {
        $date = BudgetProgressRequest::date($request);
        $query = CollectionQuery::from($request, filterable: [], sortable: ['created_at'], defaultSort: 'created_at');

        // The progress list has no other order than the budget's `created_at`, ascending.
        if ($query->descending) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $after = null;
        if ($query->after !== null) {
            $id = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;
            $start = $query->after['period_start'] ?? null;

            if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value) || ! is_string($start) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $id, 'period_start' => $start];
        }

        $page = $budgets->progressList($this->callerId($request), $date, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (BudgetProgress $progress): array => BudgetProgressResource::toArray($progress), $page->items),
            $query->cursorFor($page->next),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, BudgetQueryService $budgets): JsonResponse
    {
        $budgetId = BudgetId::assert($id);
        $date = BudgetProgressRequest::date($request);

        return Envelope::ok(BudgetProgressResource::toArray($budgets->progressOf($this->callerId($request), $budgetId, $date)));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
