<?php

namespace App\Http\Api\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a plaintext request with 400 INSECURE_TRANSPORT instead of
 * redirecting it, because by the time a redirect arrives the credential has
 * already crossed the wire in the clear (security baseline, Transport).
 * Secure responses carry a one-year Strict-Transport-Security.
 */
final class RefuseInsecureTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && config('api.require_tls')) {
            throw new ApiRefusal(ErrorCode::InsecureTransport);
        }

        $response = $next($request);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('api.hsts_max_age'));
        }

        return $response;
    }
}
