<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Controllers;

use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetQueryService;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Http\BudgetId;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetMemberResource;
use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class SharedBudgetMemberController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, string $id, SharedBudgetQueryService $budgets, DisplayNames $names): JsonResponse
    {
        $members = $budgets->members(self::callerId($request), BudgetId::assert($id));
        $displayNames = $names->of(array_map(fn (SharedBudgetMemberRecord $member): string => $member->userId, $members));

        return Envelope::ok(array_map(
            fn (SharedBudgetMemberRecord $member): array => SharedBudgetMemberResource::toArray($member, $displayNames[$member->userId] ?? null),
            $members,
        ));
    }

    public function destroy(Request $request, string $id, string $uid, SharedBudgetService $budgets): Response
    {
        if (preg_match(self::UUID, $uid) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }

        $budgets->removeMember(self::callerId($request), BudgetId::assert($id), $uid);

        return response()->noContent();
    }

    public function leave(Request $request, string $id, SharedBudgetService $budgets): Response
    {
        $budgets->leave(self::callerId($request), BudgetId::assert($id));

        return response()->noContent();
    }

    private static function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
