<?php

namespace App\Http\Api\Middleware;

use App\Http\Api\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the language of human-readable members from Accept-Language, and
 * reports the language actually used in Content-Language on every response
 * (docs/api-conventions.md, Localisation).
 */
final class ResolveContentLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Locales::resolve($request));

        $response = $next($request);

        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
