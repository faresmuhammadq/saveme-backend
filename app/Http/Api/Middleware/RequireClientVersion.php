<?php

namespace App\Http\Api\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request names its client and version (docs/api-conventions.md,
 * Client versions). The gate applies to reads as well as writes: a client too
 * old to understand a payload is not made safer by being allowed to read it.
 */
final class RequireClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = $request->headers->get('X-Client-Platform');
        $version = $request->headers->get('X-Client-Version');

        if ($platform === null || $platform === '' || $version === null || $version === '') {
            throw new ApiRefusal(ErrorCode::ClientVersionRequired);
        }

        if (! in_array($platform, config('api.supported_platforms'), true) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new ApiRefusal(ErrorCode::ClientVersionInvalid);
        }

        $minimum = (string) config("api.minimum_client_version.{$platform}");

        if (version_compare($version, $minimum, '<')) {
            throw new ApiRefusal(ErrorCode::ClientVersionUnsupported, extensions: ['minimum_version' => $minimum]);
        }

        return $next($request);
    }
}
