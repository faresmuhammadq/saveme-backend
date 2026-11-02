<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Budgeting\Application\Sync\SharedBudgetHeadsQuery;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GET /sync/shared-budgets/heads: the scope head and purge horizon of each
 * Shared Budget the caller actively belongs to, keyset-paged by id. No
 * filter, no sort. One summary line per read, counts only.
 */
final class SharedBudgetHeadsController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __invoke(Request $request, SharedBudgetHeadsQuery $heads): JsonResponse
    {
        if ($request->query->has('sort')) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $query = CollectionQuery::from($request, filterable: [], sortable: ['shared_budget_id'], defaultSort: 'shared_budget_id');

        $after = null;
        if ($query->after !== null) {
            $after = $query->after['shared_budget_id'] ?? null;

            if (! is_string($after) || preg_match(self::UUID, $after) !== 1) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }
        }

        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');
        $rows = $heads->page((string) $user->getAuthIdentifier(), $after, $query->limit + 1);

        $hasMore = count($rows) > $query->limit;
        $page = array_slice($rows, 0, $query->limit);

        Log::info('sync_shared_budget_heads_read', ['shared_budgets' => count($page), 'has_more' => $hasMore]);

        $last = $page === [] ? null : $page[array_key_last($page)]['shared_budget_id'];

        return Envelope::collection(
            $page,
            $hasMore && $last !== null ? $query->cursorFor(['shared_budget_id' => $last]) : null,
            $query->limit,
        );
    }
}
