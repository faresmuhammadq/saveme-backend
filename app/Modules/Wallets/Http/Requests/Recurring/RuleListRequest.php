<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use Illuminate\Http\Request;

/**
 * The query of `GET /wallets/{id}/recurring-rules` (spec 014, FR-022): the
 * shared collection grammar limited to the operation's `x-filterable` and
 * `x-sortable`, and a cursor valid only with the filters and sort that issued
 * it.
 */
final readonly class RuleListRequest
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}(:\d{2})?$/';

    /**
     * @param  'created_at'|'start_date'  $sort
     * @param  array{value: string, id: string}|null  $after
     */
    private function __construct(
        public CollectionQuery $query,
        public string $sort,
        public ?array $after,
    ) {}

    /**
     * @throws ApiRefusal
     */
    public static function from(Request $request): self
    {
        $query = CollectionQuery::from($request, filterable: ['status' => ['eq', 'in']], sortable: ['created_at', 'start_date'], defaultSort: 'created_at');

        foreach ($query->filters['status'] ?? [] as $condition) {
            foreach ($condition['op'] === 'in' ? explode(',', $condition['value']) : [$condition['value']] as $value) {
                if (RuleStatus::tryFrom($value) === null) {
                    throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
                }
            }
        }

        /** @var 'created_at'|'start_date' $sort */
        $sort = $query->sort;

        return new self($query, $sort, self::after($query, $sort));
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

        if (! is_string($id) || preg_match(self::UUID, $id) !== 1 || ! is_string($value) || preg_match($sort === 'start_date' ? self::DATE : self::INSTANT, $value) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidCursor);
        }

        return ['value' => $value, 'id' => $id];
    }
}
