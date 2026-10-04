<?php

namespace App\Modules\Admin\Http\Auth;

use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * A fully signed-in operator's session (spec 018, R-7). Started only after
 * the second factor; judged afresh on every request, with no cached "still
 * valid" answer: the account is read again, and the session must be inside
 * its idle and absolute limits and carry the account's current credential
 * epoch.
 */
final class OperatorSession
{
    public const SIGNED_IN_AT = 'admin.signed_in_at';

    public const LAST_SEEN_AT = 'admin.last_seen_at';

    public const EPOCH = 'admin.epoch';

    public const FACTOR_CONFIRMED_AT = 'admin.factor_confirmed_at';

    public function __construct(private readonly PendingSignIn $pending) {}

    /**
     * Signs the operator in, replaces the session identifier and ends any
     * half-finished sign-in. A sign-in proves the second factor, so it also
     * starts the re-confirmation window.
     */
    public function start(string $operatorId, int $epoch): void
    {
        $this->pending->forget();
        session()->regenerate();

        $now = now()->getTimestamp();
        session()->put([
            self::SIGNED_IN_AT => $now,
            self::LAST_SEEN_AT => $now,
            self::EPOCH => $epoch,
            self::FACTOR_CONFIRMED_AT => $now,
        ]);

        // Logging in replaces the session identifier, keeping the data above.
        $this->guard()->loginUsingId($operatorId);
    }

    /**
     * Ends the session: guard logged out, data flushed, identifier and CSRF
     * token replaced. The language choice survives, as it is not an
     * operator's data.
     */
    public function end(): void
    {
        $locale = session()->get(ResolveAdminLocale::SESSION_KEY);

        $this->guard()->logout();
        session()->invalidate();
        session()->regenerateToken();

        if ($locale !== null) {
            session()->put(ResolveAdminLocale::SESSION_KEY, $locale);
        }
    }

    /**
     * Why this session no longer qualifies, or null when it does.
     *
     * @return 'signed_out'|'state'|'epoch'|'idle'|'absolute'|null
     */
    public function qualifies(): ?string
    {
        $operator = $this->operator();

        if ($operator === null) {
            return 'signed_out';
        }

        if ($operator->state !== OperatorState::Active->value || $operator->enrolled_at === null) {
            return 'state';
        }

        if ((int) session()->get(self::EPOCH, -1) !== $operator->credential_epoch) {
            return 'epoch';
        }

        $now = now()->getTimestamp();

        if ($now - (int) session()->get(self::LAST_SEEN_AT, 0) > (int) config('admin.auth.idle_minutes') * 60) {
            return 'idle';
        }

        if ($now - (int) session()->get(self::SIGNED_IN_AT, 0) > (int) config('admin.auth.absolute_hours') * 3600) {
            return 'absolute';
        }

        return null;
    }

    public function touch(): void
    {
        session()->put(self::LAST_SEEN_AT, now()->getTimestamp());
    }

    /**
     * The account behind the session, read afresh.
     */
    public function operator(): ?OperatorAccount
    {
        $guard = $this->guard();
        $guard->forgetUser();

        $user = $guard->user();

        return $user instanceof OperatorAccount ? $user : null;
    }

    public function operatorId(): ?string
    {
        return $this->operator()?->id;
    }

    /**
     * Whether a second-factor proof is recent enough for a sensitive act
     * (FR-060).
     */
    public function hasRecentFactor(): bool
    {
        $at = (int) session()->get(self::FACTOR_CONFIRMED_AT, 0);

        return $at > 0 && now()->getTimestamp() - $at <= (int) config('admin.auth.reconfirm_minutes') * 60;
    }

    public function markFactorConfirmed(): void
    {
        session()->put(self::FACTOR_CONFIRMED_AT, now()->getTimestamp());
    }

    /**
     * Keeps this session across the operator's own credential change, which
     * moves the epoch and so ends every other session.
     */
    public function adoptEpoch(int $epoch): void
    {
        session()->put(self::EPOCH, $epoch);
    }

    private function guard(): SessionGuard
    {
        $guard = Auth::guard('operator');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The operator guard must be a session guard.');
        }

        return $guard;
    }
}
