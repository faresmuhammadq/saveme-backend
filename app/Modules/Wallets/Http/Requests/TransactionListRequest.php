<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use Illuminate\Http\Request;

/**
 * The query of `GET /wallets/{id}/transactions` (spec 012, R-11): the shared
 * collection grammar limited to the operation's `x-filterable` and
 * `x-sortable`, the bounded `in` lists, the `search` term, and a cursor that
 * is valid only with the filters, sort and search that issued it.
 */
final readonly class TransactionListRequest
{
    private const MAX_IN_VALUES = 50;

    private const SEARCH_MAX = 100;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}(:\d{2})?$/';

    /**
     * @param  'date'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     */
    private function __construct(
        public CollectionQuery $query,
        public ?string $search,
        public string $sort,
        public ?array $after,
    ) {}

    /**
     * @throws ApiRefusal
     */
    public static function from(Request $request): self
    {
        $search = $request->query->has('search') ? self::search($request->query->get('search')) : null;

        $query = CollectionQuery::from(
            $request,
            filterable: ['date' => ['eq', 'gt', 'gte', 'lt', 'lte'], 'category_id' => ['eq', 'in'], 'kind' => ['eq', 'in']],
            sortable: ['date', 'created_at'],
            defaultSort: '-date',
            binding: $search === null ? '' : 'search='.$search,
        );

        self::assertFilterValues($query);

        /** @var 'date'|'created_at' $sort */
        $sort = $query->sort;

        return new self($query, $search, $sort, self::after($query, $sort));
    }

    /**
     * The request pipeline turns a blank value into null, so a `search`
     * that is present but empty arrives as null and is refused like any blank.
     */
    private static function search(mixed $search): string
    {
        if (! is_string($search)) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        $trimmed = trim($search);
        $length = mb_strlen($trimmed);

        if ($length < 1 || $length > self::SEARCH_MAX) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return $trimmed;
    }

    private static function assertFilterValues(CollectionQuery $query): void
    {
        foreach ($query->filters as $field => $conditions) {
            foreach ($conditions as $condition) {
                $values = $condition['op'] === 'in' ? explode(',', $condition['value']) : [$condition['value']];

                if (count($values) > self::MAX_IN_VALUES) {
                    throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
                }

                foreach ($values as $value) {
                    $valid = match ($field) {
                        'date' => self::isCalendarDate($value),
                        'category_id' => preg_match(self::UUID, $value) === 1,
                        'kind' => TransactionKind::tryFrom($value) !== null,
                        default => false,
                    };

                    if (! $valid) {
                        throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
                    }
                }
            }
        }
    }

    private static function isCalendarDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * @return array{value: string, id: string}|null
     */
    private static function after(CollectionQuery $query, string $sort): ?array
    {
        if ($query->after === null) {
            return null;
        }

        $id = $query->after['id'] ?? null;
        $value = $query->after['value'] ?? null;

        if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value) || preg_match($sort === 'date' ? self::DATE : self::INSTANT, $value) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidCursor);
        }

        return ['value' => $value, 'id' => $id];
    }
}
