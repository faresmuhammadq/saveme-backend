<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate for a step of a half-finished sign-in: `admin.pending` exists, is
 * not stale, and names one of the given stages. Otherwise the operator goes
 * to step 1 (with the timeout message when the state went stale) or to the
 * step they are really at.
 */
class EnsurePendingStage
{
    public function __construct(private readonly PendingSignIn $pending) {}

    public function handle(Request $request, Closure $next, string ...$stages): Response
    {
        if (! $this->pending->exists()) {
            return redirect()->route('admin.sign-in');
        }

        if ($this->pending->isStale()) {
            $this->pending->forget();

            return redirect()->route('admin.sign-in')->with('admin.reason', 'timeout');
        }

        $stage = $this->pending->stage();

        if ($stage === null || ! in_array($stage->value, $stages, true)) {
            return redirect()->route($stage === null ? 'admin.sign-in' : PendingSignIn::routeFor($stage));
        }

        return $next($request);
    }

    public static function for(SignInStage ...$stages): string
    {
        return self::class.':'.implode(',', array_map(fn (SignInStage $stage): string => $stage->value, $stages));
    }
}
