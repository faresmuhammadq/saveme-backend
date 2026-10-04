<?php

namespace App\Modules\Billing\Http\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The InstaPay claim operation does not exist for the app (P-10 rule 3,
 * ADR-0023): any `X-Client-Platform` other than `web` gets 404. Defence in
 * depth, not a security boundary: the header is the client's own word.
 */
final class ClaimsAreWebOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->get('X-Client-Platform') !== 'web') {
            throw new ApiRefusal(ErrorCode::NotFound);
        }

        return $next($request);
    }
}
