<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\Credentials\ChangeOperatorPassword;
use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Application\SignIn\CompleteSignIn;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\PasswordRuleBroken;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\IntendedUrl;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The forced password change (spec 018, FR-004, FR-062). A new operator goes
 * on to enrol; an enrolled operator whose password was reset has already
 * passed the second factor, so the sign-in completes here.
 */
final class NewPasswordController
{
    public function show(): View
    {
        return view('admin::auth.new-password');
    }

    public function submit(Request $request, PendingSignIn $pending, ChangeOperatorPassword $change, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        $password = $request->input('password');
        $confirmation = $request->input('password_confirmation');

        if (! is_string($password) || $password === '' || $password !== $confirmation) {
            return $this->refuse(__('admin::auth.password.mismatch'));
        }

        try {
            $result = $change->handle((string) $pending->operatorId(), $password);
        } catch (PasswordRuleBroken $broken) {
            return $this->refuse(__('admin::auth.password.errors.'.$broken->violations[0]));
        } catch (PendingSignInExpired) {
            $pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
        }

        if ($result->state === OperatorState::AwaitingEnrolment) {
            $pending->advance(SignInStage::Enrolment, $result->epoch);

            return redirect()->route('admin.enrol');
        }

        $pending->advance(SignInStage::SecondFactor, $result->epoch);

        return $this->finish($pending, $complete, $session, $intended);
    }

    private function finish(PendingSignIn $pending, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        $factor = $pending->heldFactor() ?? new VerifiedFactor('code', 0);
        $operatorId = (string) $pending->operatorId();

        try {
            $epoch = $complete->handle($operatorId, $pending->epoch(), $factor);
        } catch (PendingSignInExpired) {
            $pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
        }

        $session->start($operatorId, $epoch);

        return redirect()->to($intended->pull());
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('admin.password.new')->with('admin.error', $message);
    }
}
