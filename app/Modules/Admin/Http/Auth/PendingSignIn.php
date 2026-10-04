<?php

namespace App\Modules\Admin\Http\Auth;

use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Domain\Operators\SignInStage;

/**
 * The half-finished sign-in, kept in the session and never in the guard, so a
 * missing factor can never count as signed in (spec 018, R-6). It holds an
 * identifier, a stage, two instants, a counter and the credential epoch —
 * never a password, secret, code or recovery code.
 */
final class PendingSignIn
{
    public const KEY = 'admin.pending';

    /**
     * Starts a half-finished sign-in and replaces the session identifier (FR-016).
     */
    public function begin(string $operatorId, SignInStage $stage, int $epoch): void
    {
        session()->regenerate();
        session()->put(self::KEY, [
            'operator_id' => $operatorId,
            'stage' => $stage->value,
            'started_at' => now()->getTimestamp(),
            'code_failures' => 0,
            'epoch' => $epoch,
        ]);
    }

    public function exists(): bool
    {
        return $this->state() !== null;
    }

    public function operatorId(): ?string
    {
        return $this->state()['operator_id'] ?? null;
    }

    public function stage(): ?SignInStage
    {
        $stage = $this->state()['stage'] ?? null;

        return is_string($stage) ? SignInStage::tryFrom($stage) : null;
    }

    public function epoch(): int
    {
        return (int) ($this->state()['epoch'] ?? 0);
    }

    public function isStale(): bool
    {
        $state = $this->state();
        $stage = $this->stage();

        if ($state === null || $stage === null) {
            return true;
        }

        $minutes = (int) config('admin.auth.pending_minutes.'.$stage->value);

        return now()->getTimestamp() - (int) $state['started_at'] > $minutes * 60;
    }

    /**
     * Moves to the next stage with a fresh lifetime; the epoch follows the
     * account when its own password change moved it.
     */
    public function advance(SignInStage $stage, ?int $epoch = null): void
    {
        $state = $this->state();

        if ($state === null) {
            return;
        }

        $state['stage'] = $stage->value;
        $state['started_at'] = now()->getTimestamp();
        $state['epoch'] = $epoch ?? $state['epoch'];
        session()->put(self::KEY, $state);
    }

    public function codeFailures(): int
    {
        return (int) ($this->state()['code_failures'] ?? 0);
    }

    /**
     * @return int the wrong codes so far in this half-finished sign-in
     */
    public function recordCodeFailure(): int
    {
        $state = $this->state();

        if ($state === null) {
            return 0;
        }

        $state['code_failures'] = (int) $state['code_failures'] + 1;
        session()->put(self::KEY, $state);

        return $state['code_failures'];
    }

    /**
     * Keeps which factor was proven (a kind and a count, no code) while the
     * operator finishes the steps that follow it, so the one sign-in entry
     * can name it.
     */
    public function holdFactor(VerifiedFactor $factor): void
    {
        $state = $this->state();

        if ($state === null) {
            return;
        }

        session()->put(self::KEY, $state + ['factor' => $factor->kind, 'codes_left' => $factor->codesLeft]);
    }

    public function heldFactor(): ?VerifiedFactor
    {
        $state = session()->get(self::KEY);
        $kind = is_array($state) ? ($state['factor'] ?? null) : null;

        if ($kind !== 'code' && $kind !== 'recovery_code') {
            return null;
        }

        return new VerifiedFactor($kind, (int) ($state['codes_left'] ?? 0));
    }

    public function forget(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * Where the operator must go to finish the stage they are at.
     */
    public static function routeFor(SignInStage $stage): string
    {
        return match ($stage) {
            SignInStage::SecondFactor => 'admin.sign-in.code',
            SignInStage::PasswordChange => 'admin.password.new',
            SignInStage::Enrolment, SignInStage::CodesShown => 'admin.enrol',
        };
    }

    /**
     * @return array{operator_id: string, stage: string, started_at: int, code_failures: int, epoch: int}|null
     */
    private function state(): ?array
    {
        $state = session()->get(self::KEY);

        if (! is_array($state) || ! isset($state['operator_id'], $state['stage'], $state['started_at'], $state['epoch'])) {
            return null;
        }

        /** @var array{operator_id: string, stage: string, started_at: int, code_failures: int, epoch: int} $state */
        return $state;
    }
}
