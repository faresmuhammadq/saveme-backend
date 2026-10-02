<?php

namespace App\Modules\Sync\Http\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;

/**
 * The query of a change feed: `cursor` and `limit`, nothing else (spec 013,
 * A-11). `filter[…]` and `sort` are refused, never ignored; any other
 * parameter is ignored, so a stray `since` yields a full resync.
 */
final readonly class FeedRequest
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function __construct(
        public ?string $cursor,
        public int $limit,
    ) {}

    /**
     * @throws ApiRefusal
     */
    public static function from(Request $request): self
    {
        $query = $request->query();

        foreach (array_keys($query) as $name) {
            if ($name === 'filter' || str_starts_with((string) $name, 'filter[')) {
                throw new ApiRefusal(ErrorCode::FilterFieldNotSupported);
            }
        }

        if (array_key_exists('sort', $query)) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        $cursor = $query['cursor'] ?? null;
        if ($cursor !== null && (! is_string($cursor) || $cursor === '')) {
            throw new ApiRefusal(ErrorCode::InvalidCursor);
        }

        $limit = $query['limit'] ?? null;
        if ($limit === null) {
            $limit = self::DEFAULT_LIMIT;
        } elseif (! is_string($limit) || preg_match('/^\d+$/', $limit) !== 1 || (int) $limit < 1) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return new self($cursor, min((int) $limit, self::MAX_LIMIT));
    }
}
