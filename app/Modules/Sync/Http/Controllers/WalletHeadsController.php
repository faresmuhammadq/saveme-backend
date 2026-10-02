<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Sync\WalletHead;
use App\Modules\Wallets\Application\Sync\WalletHeadsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GET /sync/wallets/heads (spec 017, ADR-0021): the scope head and purge
 * horizon of each wallet the caller actively belongs to, keyset-paged by
 * wallet id. No filter, no sort. One summary line per read, counts only.
 */
final class WalletHeadsController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __invoke(Request $request, WalletHeadsQuery $heads): JsonResponse
    {
        // The default sort must be declared sortable for CollectionQuery to accept it, so a client `sort` is refused first.
        if ($request->query->has('sort')) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $query = CollectionQuery::from($request, filterable: [], sortable: ['wallet_id'], defaultSort: 'wallet_id');

        $after = null;
        if ($query->after !== null) {
            $after = $query->after['wallet_id'] ?? null;

            if (! is_string($after) || preg_match(self::UUID, $after) !== 1) {
                throw new ApiRefusal(ErrorCode::InvalidCursor);
            }
        }

        $user = $request->user() ?? throw new RuntimeException('Route is missing auth:api.');
        $rows = $heads->page((string) $user->getAuthIdentifier(), $after, $query->limit + 1);

        $hasMore = count($rows) > $query->limit;
        $page = array_slice($rows, 0, $query->limit);

        Log::info('sync_wallet_heads_read', ['wallets' => count($page), 'has_more' => $hasMore]);

        $last = $page === [] ? null : $page[array_key_last($page)]->walletId;

        return Envelope::collection(
            array_map(fn (WalletHead $head): array => [
                'wallet_id' => $head->walletId,
                'change_seq' => $head->head,
                'purged_change_seq' => $head->purged,
            ], $page),
            $hasMore && $last !== null ? $query->cursorFor(['wallet_id' => $last]) : null,
            $query->limit,
        );
    }
}
