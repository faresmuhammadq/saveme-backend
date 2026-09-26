<?php

namespace App\Http\Api;

use Illuminate\Http\Request;

/**
 * Resolves Accept-Language to a supported language (docs/api-conventions.md,
 * Localisation): the first supported language the header names, else the
 * first supported language, which is English.
 */
final class Locales
{
    public static function resolve(Request $request): string
    {
        /** @var list<string> $supported */
        $supported = config('api.supported_locales');

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(substr($language, 0, 2));

            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $supported[0];
    }
}
