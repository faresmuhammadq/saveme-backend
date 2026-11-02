<?php

namespace App\Modules\Sharing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page the mailed invitation link opens in a browser: hands the token to
 * the mobile app, where the person previews and accepts. Nothing is spent
 * here, so a link scanner opening it cannot use up the invitation.
 */
final class SharedBudgetInvitationPageController
{
    public function __invoke(Request $request): Response
    {
        app()->setLocale($request->getPreferredLanguage(['en', 'ar']) ?? 'en');

        $token = $request->query('token');
        $valid = is_string($token) && $token !== '' && strlen($token) <= 128;

        return response()
            ->view('sharing::invite', [
                'valid' => $valid,
                'appUrl' => $valid ? $this->intentUri($token) : null,
            ], $valid ? 200 : 410)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * An Android intent URI that opens the invitation link inside the app,
     * which claims it as an app link; a browser cannot reopen the same URL
     * itself.
     */
    private function intentUri(string $token): string
    {
        $link = parse_url((string) config('sharing.links.shared_budget_invitation_url'));
        $host = ($link['host'] ?? '').(isset($link['port']) ? ':'.$link['port'] : '');

        return 'intent://'.$host.($link['path'] ?? '/invite/shared-budget').'?token='.rawurlencode($token)
            .'#Intent;scheme='.($link['scheme'] ?? 'https').';package='.config('sharing.links.android_package').';end';
    }
}
