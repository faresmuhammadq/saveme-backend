<?php

namespace App\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a server-generated UUIDv7 and every response its
 * X-Request-Id (docs/api-conventions.md, Request and correlation identifiers).
 *
 * A client's X-Correlation-Id is kept only when it matches the contract's
 * pattern, and is otherwise dropped silently. Both values go into the log
 * context, which Laravel carries into queued jobs and listeners (P-15 rule 3).
 */
final class AssignRequestId
{
    private const CORRELATION_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid7();
        Context::add('request_id', $requestId);

        $correlationId = $request->headers->get('X-Correlation-Id');
        $correlationId = is_string($correlationId) && preg_match(self::CORRELATION_PATTERN, $correlationId) === 1
            ? $correlationId
            : null;

        if ($correlationId !== null) {
            Context::add('correlation_id', $correlationId);
        }

        $response = $next($request);

        $response->headers->set('X-Request-Id', (string) Context::get('request_id', $requestId));

        if ($correlationId !== null) {
            $response->headers->set('X-Correlation-Id', $correlationId);
        }

        return $response;
    }
}
