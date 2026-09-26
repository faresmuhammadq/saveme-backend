<?php

namespace App\Http\Api\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON only, in both directions (docs/api-conventions.md, Media types):
 * 406 when Accept excludes JSON, 415 when a body is not declared as JSON, and
 * 400 MALFORMED_JSON when the body does not parse or is not an object.
 */
final class NegotiateJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->accepts(['application/json', 'application/problem+json'])) {
            throw new ApiRefusal(ErrorCode::NotAcceptable);
        }

        $body = $request->getContent();

        if ($body !== '') {
            if (! $request->isJson()) {
                throw new ApiRefusal(ErrorCode::UnsupportedMediaType);
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw new ApiRefusal(ErrorCode::MalformedJson);
            }
        }

        return $next($request);
    }
}
