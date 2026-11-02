<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetQueryService;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\PatchSharedBudgetRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\SaveSharedBudgetRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\SharedBudgetVersionRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\TransferOwnershipRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SharedBudgetController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, SharedBudgetQueryService $budgets): JsonResponse
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

        $page = $budgets->list(self::callerId($request), $query->descending, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (SharedBudgetRecord $budget): array => SharedBudgetResource::toArray($budget), $page->items),
            $query->cursorFor($page->next),
            $query->limit,
        );
    }

    public function show(Request $request, string $id, SharedBudgetQueryService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetResource::toArray($budgets->find(self::callerId($request), BudgetId::assert($id))));
    }

    public function upsert(SaveSharedBudgetRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        $saved = $budgets->save(self::callerId($request), $request->budgetId(), $request->values(), $request->version(), SharedBudgetResource::toArray(...));

        return Envelope::ok(SharedBudgetResource::toArray($saved->budget), status: $saved->created ? 201 : 200);
    }

    public function patch(PatchSharedBudgetRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetResource::toArray($budgets->patch(self::callerId($request), $request->budgetId(), $request->partial(), $request->version(), SharedBudgetResource::toArray(...))));
    }

    public function archive(SharedBudgetVersionRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetResource::toArray($budgets->archive(self::callerId($request), $request->budgetId(), $request->version(), SharedBudgetResource::toArray(...))));
    }

    public function destroy(SharedBudgetVersionRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetResource::toArray($budgets->delete(self::callerId($request), $request->budgetId(), $request->version(), SharedBudgetResource::toArray(...))));
    }

    public function transferOwnership(TransferOwnershipRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetResource::toArray($budgets->transferOwnership(self::callerId($request), $request->budgetId(), $request->newOwnerId(), $request->version(), SharedBudgetResource::toArray(...))));
    }

    private static function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
