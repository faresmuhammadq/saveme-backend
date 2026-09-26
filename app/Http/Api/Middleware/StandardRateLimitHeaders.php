<?php

namespace App\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's throttle emits X-RateLimit-*; the contract promises the unprefixed
 * RateLimit-* names (docs/api-conventions.md, Rate limiting). Renames them on
 * the way out so no response carries both.
 */
final class StandardRateLimitHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (['Limit', 'Remaining', 'Reset'] as $name) {
            if ($response->headers->has("X-RateLimit-{$name}")) {
                if (! $response->headers->has("RateLimit-{$name}") && $name !== 'Reset') {
                    $response->headers->set("RateLimit-{$name}", (string) $response->headers->get("X-RateLimit-{$name}"));
                }
                $response->headers->remove("X-RateLimit-{$name}");
            }
        }

        return $response;
    }
}
