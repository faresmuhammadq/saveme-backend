<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetQueryService;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\DeleteSharedBudgetLineRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Requests\SaveSharedBudgetLineRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetLineResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SharedBudgetLineController
{
    public function index(Request $request, string $id, SharedBudgetQueryService $budgets): JsonResponse
    {
        return Envelope::ok(array_map(
            fn (SharedBudgetLineRecord $line): array => SharedBudgetLineResource::toArray($line),
            $budgets->lines(self::callerId($request), BudgetId::assert($id)),
        ));
    }

    public function upsert(SaveSharedBudgetLineRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        $saved = $budgets->saveLine(self::callerId($request), $request->budgetId(), $request->lineId(), $request->values(), $request->version(), SharedBudgetLineResource::toArray(...));

        return Envelope::ok(SharedBudgetLineResource::toArray($saved->line), status: $saved->created ? 201 : 200);
    }

    public function destroy(DeleteSharedBudgetLineRequest $request, SharedBudgetService $budgets): JsonResponse
    {
        return Envelope::ok(SharedBudgetLineResource::toArray($budgets->deleteLine(self::callerId($request), $request->budgetId(), $request->lineId(), $request->version(), SharedBudgetLineResource::toArray(...))));
    }

    private static function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
