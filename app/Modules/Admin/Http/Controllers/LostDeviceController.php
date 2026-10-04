<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\Reenrolment\RequestReenrolment;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * "Lost your device?" (spec 018, FR-030): after a correct password, open a
 * re-enrolment request. The half-finished sign-in ends, and the outcome page
 * is the response itself.
 */
final class LostDeviceController
{
    public function show(): View
    {
        return view('admin::auth.lost-device');
    }

    public function submit(PendingSignIn $pending, RequestReenrolment $request): View|RedirectResponse
    {
        try {
            $outcome = $request->handle((string) $pending->operatorId());
        } catch (OperatorLocked $locked) {
            return redirect()->route('admin.sign-in.lost-device')
                ->with('admin.error', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));
        } catch (PendingSignInExpired) {
            $pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
        }

        $pending->forget();
        session()->regenerate();

        return view('admin::auth.lost-device-sent', ['outcome' => $outcome]);
    }
}
