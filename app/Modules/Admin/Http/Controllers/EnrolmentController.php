<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Application\Enrolment\BeginEnrolment;
use App\Modules\Admin\Application\Enrolment\ConfirmEnrolment;
use App\Modules\Admin\Application\SignIn\CompleteSignIn;
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
 * Enrolment of the authenticator and the one display of the recovery codes
 * (spec 018, FR-020 to FR-022, R-11). The codes exist in the response of the
 * confirming POST and nowhere else; a reload shows the form without them.
 */
final class EnrolmentController
{
    public function __construct(private readonly PendingSignIn $pending) {}

    public function show(BeginEnrolment $begin): View|RedirectResponse
    {
        if ($this->pending->stage() === SignInStage::CodesShown) {
            return view('admin::auth.recovery-codes', ['codes' => null]);
        }

        try {
            $setup = $begin->handle((string) $this->pending->operatorId());
        } catch (PendingSignInExpired) {
            return $this->expired();
        }

        return view('admin::auth.enrol', ['setup' => $setup]);
    }

    public function submit(Request $request, ConfirmEnrolment $confirm): View|RedirectResponse
    {
        if ($this->pending->stage() !== SignInStage::Enrolment) {
            return redirect()->route('admin.enrol');
        }

        $code = $request->input('one_time_code');

        try {
            $issued = $confirm->handle((string) $this->pending->operatorId(), is_string($code) ? $code : '');
        } catch (SecondFactorInvalid) {
            return redirect()->route('admin.enrol')->with('admin.error', __('admin::auth.enrol.invalid'));
        } catch (OperatorLocked $locked) {
            return redirect()->route('admin.enrol')->with('admin.error', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));
        } catch (PendingSignInExpired) {
            return $this->expired();
        }

        $this->pending->holdFactor(new VerifiedFactor('code', count($issued->codes)));
        $this->pending->advance(SignInStage::CodesShown);

        return view('admin::auth.recovery-codes', ['codes' => $issued->codes]);
    }

    public function done(Request $request, CompleteSignIn $complete, OperatorSession $session, IntendedUrl $intended): RedirectResponse
    {
        if ($this->pending->stage() !== SignInStage::CodesShown) {
            return redirect()->route('admin.enrol');
        }

        if (! $request->boolean('saved')) {
            return redirect()->route('admin.enrol')->with('admin.error', __('admin::auth.codes.must_confirm'));
        }

        $operatorId = (string) $this->pending->operatorId();

        try {
            $epoch = $complete->handle($operatorId, $this->pending->epoch(), $this->pending->heldFactor() ?? new VerifiedFactor('code', 0));
        } catch (PendingSignInExpired) {
            return $this->expired();
        }

        $session->start($operatorId, $epoch);

        return redirect()->to($intended->pull());
    }

    private function expired(): RedirectResponse
    {
        $this->pending->forget();

        return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
    }
}
