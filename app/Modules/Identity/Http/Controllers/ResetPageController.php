<?php

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page the mailed reset link opens in a browser: hands the token to the
 * mobile app, which collects the new password. Nothing is redeemed here.
 */
final class ResetPageController
{
    public function __invoke(Request $request): Response
    {
        app()->setLocale($request->getPreferredLanguage(['en', 'ar']) ?? 'en');

        $token = $request->query('token');
        $valid = is_string($token) && $token !== '' && strlen($token) <= 128;

        return response()
            ->view('identity::reset', [
                'valid' => $valid,
                'appUrl' => $valid ? $this->intentUri($token) : null,
            ], $valid ? 200 : 410)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * An Android intent URI that opens the reset link inside the app, which
     * claims it as an app link; a browser cannot reopen the same URL itself.
     */
    private function intentUri(string $token): string
    {
        $link = parse_url((string) config('identity.links.reset_url'));
        $host = ($link['host'] ?? '').(isset($link['port']) ? ':'.$link['port'] : '');

        return 'intent://'.$host.($link['path'] ?? '/reset-password').'?token='.rawurlencode($token)
            .'#Intent;scheme='.($link['scheme'] ?? 'https').';package='.config('identity.links.android_package').';end';
    }
}
