<?php

namespace App\Modules\Sharing\Http\Middleware;

use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Sharing\Application\InvitationService;
use App\Modules\Sharing\Http\PathIds;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inviter's budget of 20 invitations per 24 hours (security baseline,
 * Route budgets). Registered as `sharing.invite-budget` and placed after the
 * verification gate and before the body is validated, so a spent budget is
 * 429 before 422 (spec 016, section 6); the path identifiers are checked
 * first, so a malformed id still wins. It only looks: the budget is spent by
 * InvitationService after an applied create, so a replay or a refusal costs
 * nothing.
 */
final class EnforceInviteBudget
{
    public function handle(Request $request, Closure $next): Response
    {
        PathIds::wallet($request->route('id'));
        PathIds::invitation($request->route('iid'));

        $key = InvitationService::budgetKey((string) $request->user()?->getAuthIdentifier());

        if (RateLimiter::tooManyAttempts($key, (int) config('sharing.throttle.invites_per_day'))) {
            throw new ApiRefusal(ErrorCode::RateLimited, retryAfterSeconds: RateLimiter::availableIn($key));
        }

        return $next($request);
    }
}
