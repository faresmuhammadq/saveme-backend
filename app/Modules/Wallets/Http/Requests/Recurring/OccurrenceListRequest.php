<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Collections\CollectionQuery;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Recurring\OccurrenceStatus;
use Illuminate\Http\Request;

/**
 * The query of `GET /wallets/{id}/occurrences` (spec 014, FR-022): the shared
 * collection grammar limited to the operation's `x-filterable` and
 * `x-sortable`, the bounded `in` list of rules, and a cursor valid only with
 * the filters and sort that issued it.
 */
final readonly class OccurrenceListRequest
{
    private const MAX_IN_VALUES = 50;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}(:\d{2})?$/';

    /**
     * @param  'date'|'created_at'  $sort
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
        $query = CollectionQuery::from(
            $request,
            filterable: ['recurring_rule_id' => ['eq', 'in'], 'status' => ['eq', 'in'], 'date' => ['eq', 'gt', 'gte', 'lt', 'lte']],
            sortable: ['date', 'created_at'],
            defaultSort: 'date',
        );

        self::assertFilterValues($query);

        /** @var 'date'|'created_at' $sort */
        $sort = $query->sort;

        return new self($query, $sort, self::after($query, $sort));
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
                        'recurring_rule_id' => preg_match(self::UUID, $value) === 1,
                        'status' => OccurrenceStatus::tryFrom($value) !== null,
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
