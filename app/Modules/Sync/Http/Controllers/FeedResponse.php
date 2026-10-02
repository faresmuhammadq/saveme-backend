<?php

namespace App\Modules\Sync\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sync\Application\FeedResult;
use App\Modules\Sync\Domain\Feed\FeedRow;
use Illuminate\Http\JsonResponse;

/**
 * A feed page on the wire: `{type, record}` rows and the collection meta plus
 * `sync_cursor` (never null).
 */
final class FeedResponse
{
    public static function from(FeedResult $result): JsonResponse
    {
        return Envelope::ok(
            array_map(fn (FeedRow $row): array => ['type' => $row->type, 'record' => $row->record], $result->rows),
            [
                'next_cursor' => $result->hasMore ? $result->syncCursor : null,
                'has_more' => $result->hasMore,
                'limit' => $result->limit,
                'sync_cursor' => $result->syncCursor,
                'sync_position' => $result->syncPosition,
            ],
        );
    }
}
