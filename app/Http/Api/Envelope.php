<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The single success shape (docs/api-conventions.md, Success envelope):
 * `{ "data": …, "meta": … }`, with `meta` always an object — an empty PHP
 * array would serialise to `[]`, which the contract refuses.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function ok(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'meta' => (object) $meta], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function accepted(array $data): JsonResponse
    {
        return self::ok($data, status: 202);
    }

    public static function noContent(): Response
    {
        return new Response(status: 204);
    }

    /**
     * @param  list<mixed>  $items
     */
    public static function collection(array $items, ?string $nextCursor, int $limit): JsonResponse
    {
        return self::ok($items, [
            'next_cursor' => $nextCursor,
            'has_more' => $nextCursor !== null,
            'limit' => $limit,
        ]);
    }
}
