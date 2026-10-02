<?php

namespace App\Modules\Budgeting\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\Requests\BudgetVersionRequest;
use App\Modules\Budgeting\Http\Requests\PatchBudgetRequest;
use App\Modules\Budgeting\Http\Requests\SaveBudgetRequest;
use App\Modules\Budgeting\Http\Resources\BudgetResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BudgetController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, BudgetQueryService $budgets): JsonResponse
    {
        $query = CollectionQuery::from($request, filterable: [], sortable: ['created_at'], defaultSort: 'created_at');

        $after = null;
        if ($query->after !== null) {
            $id = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;

            if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value)) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $id];
        }

        $page = $budgets->list($this->callerId($request), $query->descending, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (BudgetRecord $budget): array => BudgetResource::toArray($budget), $page->items),
            $query->cursorFor($page->next),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, BudgetQueryService $budgets): JsonResponse
    {
        return Envelope::ok(BudgetResource::toArray($budgets->find($this->callerId($request), BudgetId::assert($id))));
    }

    public function upsert(SaveBudgetRequest $request, BudgetService $budgets): JsonResponse
    {
        $saved = $budgets->save(
            $this->callerId($request),
            $request->budgetId(),
            $request->values(),
            $request->version(),
            BudgetResource::toArray(...),
        );

        return Envelope::ok(BudgetResource::toArray($saved->budget), status: $saved->created ? 201 : 200);
    }

    public function patch(PatchBudgetRequest $request, BudgetService $budgets): JsonResponse
    {
        $budget = $budgets->patch($this->callerId($request), $request->budgetId(), $request->amountMinor(), $request->version(), BudgetResource::toArray(...));

        return Envelope::ok(BudgetResource::toArray($budget));
    }

    public function archive(BudgetVersionRequest $request, BudgetService $budgets): JsonResponse
    {
        $budget = $budgets->archive($this->callerId($request), $request->budgetId(), $request->version(), BudgetResource::toArray(...));

        return Envelope::ok(BudgetResource::toArray($budget));
    }

    public function destroy(BudgetVersionRequest $request, BudgetService $budgets): JsonResponse
    {
        $budget = $budgets->delete($this->callerId($request), $request->budgetId(), $request->version(), BudgetResource::toArray(...));

        return Envelope::ok(BudgetResource::toArray($budget));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
