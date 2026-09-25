<?php

namespace App\Modules\Admin\Http\Livewire\Concerns;

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Exceptions\InvalidAuditReason;
use Livewire\Attributes\Locked;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Makes an audited back-office action ask for a reason first (spec 006,
 * FR-017, FR-018; contracts/reason-prompt.md).
 *
 *     public function reject(string $claimId): void
 *     {
 *         $this->requireReason('claim.reject', 'rejectClaim', [$claimId]);
 *     }
 *
 *     protected function rejectClaim(string $claimId, AuditReason $reason): void { … }
 *
 * The audited method is protected, so the browser cannot call it: the only
 * way in is confirmReason(), which refuses a blank reason. The pending action
 * is locked state, so the browser cannot redirect it to another method.
 * Render <x-admin::reason-prompt> in the component's view.
 */
trait RequiresReason
{
    #[Locked]
    public ?string $reasonAction = null;

    #[Locked]
    public ?string $reasonMethod = null;

    /** @var list<string|int|float|bool|null> */
    #[Locked]
    public array $reasonArguments = [];

    #[Locked]
    public bool $reasonOpen = false;

    /**
     * @param  array<int, mixed>  $arguments  scalars only; they are kept in public state
     */
    protected function requireReason(string $actionKey, string $method, array $arguments = []): void
    {
        $this->assertAuditedMethod($method);

        $scalars = [];

        foreach ($arguments as $argument) {
            if (! is_scalar($argument) && $argument !== null) {
                throw new LogicException('Audited action arguments must be scalars (constitution P-18 rule 5).');
            }

            $scalars[] = $argument;
        }

        $this->resetErrorBag('reason');
        $this->reasonAction = $actionKey;
        $this->reasonMethod = $method;
        $this->reasonArguments = $scalars;
        $this->reasonOpen = true;
    }

    public function confirmReason(string $reason): void
    {
        $action = $this->reasonAction;
        $method = $this->reasonMethod;
        $arguments = $this->reasonArguments;

        if (! $this->reasonOpen || $method === null || $action === null) {
            return;
        }

        try {
            $auditReason = new AuditReason($reason);
        } catch (InvalidAuditReason $invalid) {
            $this->addError('reason', __('admin::reason.errors.'.$invalid->rule, ['max' => AuditReason::MAX_LENGTH]));

            return;
        }

        $this->assertAuditedMethod($method);
        $this->authorizeReason($action);

        $this->resetReason();

        $this->{$method}(...$arguments, reason: $auditReason);
    }

    public function cancelReason(): void
    {
        $this->resetReason();
    }

    /**
     * Authorize the audited action again at confirmation (constitution P-18
     * rule 7). Screens override this; the default allows, because every
     * operator is an admin in MVP.
     */
    protected function authorizeReason(string $actionKey): void
    {
        //
    }

    private function resetReason(): void
    {
        $this->reasonAction = null;
        $this->reasonMethod = null;
        $this->reasonArguments = [];
        $this->reasonOpen = false;
        $this->resetErrorBag('reason');
    }

    private function assertAuditedMethod(string $method): void
    {
        if (! method_exists($this, $method)) {
            throw new LogicException("Audited method [{$method}] does not exist on ".static::class.'.');
        }

        $reflection = new ReflectionMethod($this, $method);

        if (! $reflection->isProtected()) {
            throw new LogicException("Audited method [{$method}] must be protected, so the browser cannot call it without a reason.");
        }

        $parameters = $reflection->getParameters();
        $last = end($parameters);
        $type = $last === false ? null : $last->getType();

        if ($last === false || $last->getName() !== 'reason' || ! $type instanceof ReflectionNamedType || $type->getName() !== AuditReason::class) {
            throw new LogicException("Audited method [{$method}] must take AuditReason \$reason as its last parameter.");
        }
    }
}
