<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Verification\VerifyAddress;
use App\Modules\Identity\Domain\Refusals\VerificationLinkInvalid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page the mailed verification link opens in a browser: redeems the
 * token, says so, and sends the person back to the mobile app.
 */
final class VerificationPageController
{
    public function __invoke(Request $request, VerifyAddress $verify): Response
    {
        app()->setLocale($request->getPreferredLanguage(['en', 'ar']) ?? 'en');

        try {
            $token = $request->query('token');

            if (! is_string($token) || $token === '' || strlen($token) > 128) {
                throw new VerificationLinkInvalid;
            }

            $verify->handle($token);
            $verified = true;
        } catch (VerificationLinkInvalid) {
            $verified = false;
        }

        return response()
            ->view('identity::verified', [
                'verified' => $verified,
                'appUrl' => config('identity.links.app_url'),
            ], $verified ? 200 : 410)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
