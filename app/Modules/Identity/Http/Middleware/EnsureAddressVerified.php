<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\VerificationGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The verification gate for wallet writes (security baseline): an unverified
 * address may read, manage its profile and its sessions, but not write to any
 * wallet. Refused with 403 EMAIL_NOT_VERIFIED so the app can offer a resend.
 *
 * Applied by other modules as the alias `address.verified`.
 */
final class EnsureAddressVerified
{
    public function __construct(private readonly VerificationGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->gate->assertVerified((string) $request->user()?->getAuthIdentifier());

        return $next($request);
    }
}
