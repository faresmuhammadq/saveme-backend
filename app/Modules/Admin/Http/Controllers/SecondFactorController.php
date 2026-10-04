<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Application\Operators\OperatorDirectory;
use App\Modules\Admin\Application\SignIn\CompleteSignIn;
use App\Modules\Admin\Application\SignIn\SecondFactorVerifier;
use App\Modules\Admin\Domain\Operators\FactorPurpose;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\IntendedUrl;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step 2: a one-time code, or a recovery code (spec 018, FR-010, FR-023). The
 * half-finished sign-in is discarded after five wrong answers (FR-014).
 */
final class SecondFactorController
{
    private const MAX_FAILURES = 5;

    public function __construct(
        private readonly PendingSignIn $pending,
        private readonly OperatorDirectory $directory,
    ) {}

    public function show(): View
    {
        $operatorId = (string) $this->pending->operatorId();

        return view('admin::auth.second-factor', [
            'email' => $this->directory->emailOf($operatorId),
            'codesLeft' => $this->codesLeft($operatorId),
            'serverTime' => now()->getTimestamp(),
            'showClockHint' => $this->pending->codeFailures() >= 2,
        ]);
    }

    public function submit(Request $request, SecondFactorVerifier $verifier, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        return $this->verify($request, 'one_time_code', 'admin.sign-in.code', __('admin::auth.second_factor.invalid'), $verifier, $complete, $session, $intended);
    }

    public function showRecovery(): View
    {
        return view('admin::auth.recovery', [
            'codesLeft' => $this->codesLeft((string) $this->pending->operatorId()),
        ]);
    }

    public function submitRecovery(Request $request, SecondFactorVerifier $verifier, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        return $this->verify($request, 'recovery_code', 'admin.sign-in.recovery', __('admin::auth.recovery.invalid'), $verifier, $complete, $session, $intended);
    }

    private function verify(Request $request, string $field, string $retryRoute, string $invalidMessage, SecondFactorVerifier $verifier, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        $operatorId = (string) $this->pending->operatorId();
        $input = $request->input($field);

        try {
            $factor = $verifier->verify($operatorId, is_string($input) ? $input : '', FactorPurpose::SignIn);
        } catch (SecondFactorInvalid) {
            return $this->wrong($retryRoute, $invalidMessage);
        } catch (OperatorLocked $locked) {
            return redirect()->route($retryRoute)->with('admin.error', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));
        }

        // An enrolled operator on a temporary password still has to choose a new one (FR-063).
        if ($this->mustChoosePassword($operatorId)) {
            $this->pending->holdFactor($factor);
            $this->pending->advance(SignInStage::PasswordChange);

            return redirect()->route('admin.password.new');
        }

        return $this->finish($factor, $operatorId, $complete, $session, $intended);
    }

    private function finish(VerifiedFactor $factor, string $operatorId, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        try {
            $epoch = $complete->handle($operatorId, $this->pending->epoch(), $factor);
        } catch (PendingSignInExpired) {
            $this->pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
        }

        $session->start($operatorId, $epoch);

        $redirect = redirect()->to($intended->pull());

        return $factor->kind === 'recovery_code'
            ? $redirect->with('admin.notice', __('admin::auth.recovery.signed_in', ['remaining' => $factor->codesLeft]))
            : $redirect;
    }

    private function wrong(string $retryRoute, string $message): RedirectResponse
    {
        if ($this->pending->recordCodeFailure() >= self::MAX_FAILURES) {
            $this->pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'codes');
        }

        return redirect()->route($retryRoute)->with('admin.error', $message);
    }

    private function codesLeft(string $operatorId): int
    {
        $view = $this->directory->view($operatorId);

        return $view === null ? 0 : $view->recoveryCodesLeft;
    }

    private function mustChoosePassword(string $operatorId): bool
    {
        return $this->directory->view($operatorId)?->state === OperatorState::AwaitingPasswordChange->value;
    }
}
