<?php

namespace App\Modules\Wallets\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Activity\ActivityQueryService;
use App\Modules\Wallets\Application\Activity\ActivityView;
use App\Modules\Wallets\Http\Requests\WalletId;
use App\Modules\Wallets\Http\Resources\ActivityEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * GET /wallets/{id}/activity (row S-07): who did what, newest first, for every
 * member of the wallet (ADR-0020).
 */
final class ActivityController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, string $id, ActivityQueryService $activity): JsonResponse
    {
        $walletId = WalletId::assert($id);

        if ($request->query->has('sort')) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $query = CollectionQuery::from($request, filterable: [], sortable: ['occurred_at'], defaultSort: 'occurred_at');

        $after = null;
        if ($query->after !== null) {
            $cursorId = $query->after['id'] ?? null;
            $value = $query->after['value'] ?? null;

            if (! is_string($cursorId) || preg_match(self::UUID, $cursorId) !== 1 || ! is_string($value)) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }

            $after = ['value' => $value, 'id' => $cursorId];
        }

        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');
        $page = $activity->page((string) $user->getAuthIdentifier(), $walletId, $after, $query->limit);

        return Envelope::collection(
            array_map(fn (ActivityView $view): array => ActivityEntryResource::toArray($view), $page['items']),
            $query->cursorFor($page['next']),
            $query->limit,
        );
    }
}
