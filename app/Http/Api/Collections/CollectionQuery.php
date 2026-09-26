<?php

namespace App\Http\Api\Collections;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\Request;

/**
 * The collection grammar every list endpoint shares (docs/api-conventions.md,
 * Collections): cursor, limit, filter[field]=op:value and sort. Anything the
 * operation does not declare is refused, never ignored.
 *
 * Cursors are opaque to clients. Each one is bound to the path, filter and
 * sort that issued it; any other combination is INVALID_CURSOR.
 */
final readonly class CollectionQuery
{
    private const MAX_LIMIT = 200;

    /**
     * @param  array<string, list<array{op: string, value: string}>>  $filters
     * @param  array<string, mixed>|null  $after
     */
    private function __construct(
        public array $filters,
        public string $sort,
        public bool $descending,
        public int $limit,
        public ?array $after,
        private string $fingerprint,
    ) {}

    /**
     * @param  array<string, list<string>>  $filterable  field → allowed operators
     * @param  list<string>  $sortable
     */
    public static function from(Request $request, array $filterable, array $sortable, string $defaultSort, int $defaultLimit = 50): self
    {
        $filters = self::filters($request, $filterable);
        [$sort, $descending] = self::sort($request->query('sort', $defaultSort), $sortable);
        $limit = self::limit($request->query('limit'), $defaultLimit);
        $fingerprint = hash('sha256', $request->path().'|'.json_encode($filters).'|'.$sort.'|'.(int) $descending);

        return new self($filters, $sort, $descending, $limit, self::cursor($request->query('cursor'), $fingerprint), $fingerprint);
    }

    /**
     * The first value filtered with `eq` on a field, if any.
     */
    public function equals(string $field): ?string
    {
        foreach ($this->filters[$field] ?? [] as $condition) {
            if ($condition['op'] === 'eq') {
                return $condition['value'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $position
     */
    public function cursorFor(?array $position): ?string
    {
        if ($position === null) {
            return null;
        }

        return rtrim(strtr(base64_encode((string) json_encode(['k' => substr($this->fingerprint, 0, 16), 'p' => $position])), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, list<string>>  $filterable
     * @return array<string, list<array{op: string, value: string}>>
     */
    private static function filters(Request $request, array $filterable): array
    {
        $given = $request->query('filter', []);

        if (! is_array($given)) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        $filters = [];
        foreach ($given as $field => $expressions) {
            if (! array_key_exists((string) $field, $filterable)) {
                throw new ApiRefusal(ErrorCode::FilterFieldNotSupported);
            }

            foreach ((array) $expressions as $expression) {
                if (! is_string($expression) || $expression === '') {
                    throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
                }

                [$op, $value] = preg_match('/^(eq|ne|lt|lte|gt|gte|in):(.+)$/', $expression, $match) === 1
                    ? [$match[1], $match[2]]
                    : ['eq', $expression];

                if (! in_array($op, $filterable[(string) $field], true)) {
                    throw new ApiRefusal(ErrorCode::FilterOperatorNotSupported);
                }

                $filters[(string) $field][] = ['op' => $op, 'value' => $value];
            }
        }

        return $filters;
    }

    /**
     * @param  list<string>  $sortable
     * @return array{string, bool}
     */
    private static function sort(mixed $sort, array $sortable): array
    {
        if (! is_string($sort) || preg_match('/^-?[a-z_]+(,-?[a-z_]+)*$/', $sort) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        // `id` is the tiebreaker the server appends anyway (Sorting).
        $fields = array_values(array_filter(explode(',', $sort), fn (string $field): bool => ltrim($field, '-') !== 'id'));

        if (count($fields) !== 1 || ! in_array(ltrim($fields[0], '-'), $sortable, true)) {
            throw new ApiRefusal(ErrorCode::SortFieldNotSupported);
        }

        return [ltrim($fields[0], '-'), str_starts_with($fields[0], '-')];
    }

    private static function limit(mixed $limit, int $default): int
    {
        if ($limit === null) {
            return $default;
        }

        if (! is_string($limit) || preg_match('/^\d+$/', $limit) !== 1 || (int) $limit < 1) {
            throw new ApiRefusal(ErrorCode::InvalidQueryParameter);
        }

        return min((int) $limit, self::MAX_LIMIT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cursor(mixed $cursor, string $fingerprint): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $decoded = is_string($cursor) ? json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true) : null;

        if (! is_array($decoded) || ($decoded['k'] ?? null) !== substr($fingerprint, 0, 16) || ! is_array($decoded['p'] ?? null)) {
            throw new ApiRefusal(ErrorCode::InvalidCursor);
        }

        return $decoded['p'];
    }
}
