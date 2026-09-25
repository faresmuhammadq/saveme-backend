<?php

namespace App\Modules\Admin\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the back-office language for the request (spec 006, FR-037, R-7):
 * the operator's choice for this session, else the browser's first Arabic or
 * English preference, else English. Direction follows from the language.
 */
class ResolveAdminLocale
{
    public const SESSION_KEY = 'admin.locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('admin.supported_locales');

        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($chosen) && in_array($chosen, $supported, true)) {
            return $chosen;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('-', '_', $language), '_') ?: '');

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return 'en';
    }
}
