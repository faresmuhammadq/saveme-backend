<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\SignIn\CheckPassword;
use App\Modules\Admin\Domain\Operators\Refusals\InvalidOperatorCredentials;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step 1: email and password (spec 018, FR-010, FR-013). A correct password
 * starts a half-finished sign-in in the session; it does not sign anyone in.
 */
final class SignInController
{
    public function show(): View
    {
        return view('admin::auth.sign-in');
    }

    public function submit(Request $request, CheckPassword $check, PendingSignIn $pending): RedirectResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return $this->refuse($request, __('admin::auth.sign_in.invalid'));
        }

        try {
            $accepted = $check->handle($email, $password);
        } catch (InvalidOperatorCredentials) {
            return $this->refuse($request, __('admin::auth.sign_in.invalid'));
        } catch (OperatorLocked $locked) {
            return $this->refuse($request, trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));
        }

        $pending->begin($accepted->operatorId, $accepted->stage, $accepted->epoch);

        return redirect()->route(PendingSignIn::routeFor($accepted->stage));
    }

    /**
     * "Not you?": discards the half-finished sign-in and starts again.
     */
    public function restart(PendingSignIn $pending): RedirectResponse
    {
        $pending->forget();
        session()->regenerate();

        return redirect()->route('admin.sign-in');
    }

    private function refuse(Request $request, string $message): RedirectResponse
    {
        return redirect()->route('admin.sign-in')
            ->with('admin.error', $message)
            ->withInput(['email' => (string) $request->input('email')]);
    }
}
