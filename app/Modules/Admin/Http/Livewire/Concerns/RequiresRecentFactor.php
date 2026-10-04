<?php

namespace App\Modules\Admin\Http\Livewire\Concerns;

use App\Modules\Admin\Application\SignIn\SecondFactorVerifier;
use App\Modules\Admin\Domain\Operators\FactorPurpose;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Http\Auth\OperatorSession;
use Livewire\Attributes\Locked;
use LogicException;
use ReflectionMethod;

/**
 * Makes a sensitive back-office action ask for a fresh second-factor proof
 * (spec 018, FR-060, FR-061; contracts/second-factor.md, Re-confirmation
 * trait). Later features opt their own actions in with this one marker:
 *
 *     public function regenerate(): void
 *     {
 *         $this->requireRecentFactor('regenerateCodes');
 *     }
 *
 *     protected function regenerateCodes(): void { … }
 *
 * The protected method runs at once when the last proof is under 10 minutes
 * old; otherwise the factor prompt opens, and the method runs only from
 * confirmFactor() after a correct code or recovery code. The browser cannot
 * call the protected method, and the pending call is locked state. It
 * composes with RequiresReason: the protected method may call requireReason().
 * Render <x-admin::factor-prompt /> in the component's view.
 */
trait RequiresRecentFactor
{
    #[Locked]
    public ?string $factorMethod = null;

    /** @var list<string|int|float|bool|null> */
    #[Locked]
    public array $factorArguments = [];

    #[Locked]
    public bool $factorOpen = false;

    /**
     * @param  array<int, mixed>  $arguments  scalars only; they are kept in public state
     */
    protected function requireRecentFactor(string $method, array $arguments = []): void
    {
        $this->assertProtectedMethod($method);

        $scalars = [];

        foreach ($arguments as $argument) {
            if (! is_scalar($argument) && $argument !== null) {
                throw new LogicException('Re-confirmed action arguments must be scalars (constitution P-18 rule 5).');
            }

            $scalars[] = $argument;
        }

        if (app(OperatorSession::class)->hasRecentFactor()) {
            $this->{$method}(...$scalars);

            return;
        }

        $this->resetErrorBag('one_time_code');
        $this->factorMethod = $method;
        $this->factorArguments = $scalars;
        $this->factorOpen = true;
    }

    public function confirmFactor(string $oneTimeCode): void
    {
        $method = $this->factorMethod;
        $arguments = $this->factorArguments;

        if (! $this->factorOpen || $method === null) {
            return;
        }

        $this->assertProtectedMethod($method);

        $operatorId = app(OperatorSession::class)->operatorId();
        abort_if($operatorId === null, 401);

        try {
            app(SecondFactorVerifier::class)->verify($operatorId, $oneTimeCode, FactorPurpose::Reconfirm);
        } catch (SecondFactorInvalid) {
            $this->addError('one_time_code', __('admin::auth.factor.invalid'));

            return;
        } catch (OperatorLocked $locked) {
            $this->addError('one_time_code', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));

            return;
        }

        app(OperatorSession::class)->markFactorConfirmed();
        $this->resetFactor();

        $this->{$method}(...$arguments);
    }

    public function cancelFactor(): void
    {
        $this->resetFactor();
    }

    private function resetFactor(): void
    {
        $this->factorMethod = null;
        $this->factorArguments = [];
        $this->factorOpen = false;
        $this->resetErrorBag('one_time_code');
    }

    private function assertProtectedMethod(string $method): void
    {
        if (! method_exists($this, $method)) {
            throw new LogicException("Re-confirmed method [{$method}] does not exist on ".static::class.'.');
        }

        if (! (new ReflectionMethod($this, $method))->isProtected()) {
            throw new LogicException("Re-confirmed method [{$method}] must be protected, so the browser cannot call it without a fresh code.");
        }
    }
}
