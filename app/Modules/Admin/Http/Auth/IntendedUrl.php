<?php

namespace App\Modules\Admin\Http\Auth;

use Illuminate\Http\Request;

/**
 * The back-office page first asked for, so sign-in can return to it (FR-015).
 * Only a same-host path under /admin is ever kept or followed, so it can
 * never be used as an open redirect.
 */
final class IntendedUrl
{
    public const KEY = 'admin.intended';

    public function remember(Request $request): void
    {
        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            return;
        }

        $path = $request->getRequestUri();

        if ($this->isBackOfficePath($path)) {
            session()->put(self::KEY, $path);
        }
    }

    /**
     * Where to send the operator now: the remembered page, else the overview.
     */
    public function pull(): string
    {
        $path = session()->pull(self::KEY);

        return is_string($path) && $this->isBackOfficePath($path) ? $path : route('admin.overview');
    }

    private function isBackOfficePath(string $path): bool
    {
        if (! str_starts_with($path, '/admin') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return false;
        }

        $rest = substr($path, strlen('/admin'));

        return $rest === '' || in_array($rest[0], ['/', '?', '#'], true);
    }
}
