<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\SignIn\SignOut;
use App\Modules\Admin\Http\Auth\OperatorSession;
use Illuminate\Http\RedirectResponse;

/**
 * Sign-out ends the session at once and writes its one audit entry
 * (spec 018, FR-041, FR-080).
 */
final class SignOutController
{
    public function __invoke(OperatorSession $session, SignOut $signOut): RedirectResponse
    {
        $operatorId = $session->operatorId();

        if ($operatorId !== null) {
            $signOut->handle($operatorId);
        }

        $session->end();

        return redirect()->route('admin.sign-in')->with('admin.reason', 'signed_out');
    }
}
