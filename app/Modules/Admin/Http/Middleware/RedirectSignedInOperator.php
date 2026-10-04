<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Http\Auth\OperatorSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate for guest pages: an operator who is fully signed in has no use
 * for the sign-in page.
 */
class RedirectSignedInOperator
{
    public function __construct(private readonly OperatorSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->session->qualifies() === null) {
            return redirect()->route('admin.overview');
        }

        return $next($request);
    }
}
