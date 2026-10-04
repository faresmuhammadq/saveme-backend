<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Http\Auth\IntendedUrl;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lock on every back-office page and component update (spec 018, R-7,
 * R-8): a fully signed-in, active, enrolled operator, inside the idle and
 * absolute limits, whose credential epoch still matches. Also a Livewire
 * persistent middleware, so the shared update endpoint re-runs it for every
 * component that came from a guarded page.
 */
class EnsureOperatorSignedIn
{
    public function __construct(
        private readonly OperatorSession $session,
        private readonly PendingSignIn $pending,
        private readonly IntendedUrl $intended,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reason = $this->session->qualifies();

        if ($reason === null) {
            $this->session->touch();

            return $next($request);
        }

        $isUpdate = $request->hasHeader('X-Livewire');

        // A half-finished sign-in is sent back to the step it has not finished,
        // and is not disturbed.
        if ($reason === 'signed_out') {
            if ($isUpdate) {
                abort(401);
            }

            $this->intended->remember($request);
            $stage = $this->pending->stage();

            if ($stage !== null && ! $this->pending->isStale()) {
                return redirect()->route(PendingSignIn::routeFor($stage));
            }

            return redirect()->route('admin.sign-in');
        }

        $this->session->end();

        if ($isUpdate) {
            abort(401);
        }

        $this->intended->remember($request);

        return redirect()->route('admin.sign-in')->with('admin.reason', $reason);
    }
}
