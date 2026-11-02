<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetReports;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\Requests\BudgetProgressRequest;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetProgressResource;
use App\Modules\Identity\Application\Profiles\DisplayNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SharedBudgetProgressController
{
    public function show(Request $request, string $id, SharedBudgetReports $reports, DisplayNames $names): JsonResponse
    {
        $budgetId = BudgetId::assert($id);
        $date = BudgetProgressRequest::date($request);
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        $progress = $reports->progress((string) $user->getAuthIdentifier(), $budgetId, $date);

        return Envelope::ok(SharedBudgetProgressResource::toArray($progress, $names->of(array_keys($progress->byMember))));
    }
}
