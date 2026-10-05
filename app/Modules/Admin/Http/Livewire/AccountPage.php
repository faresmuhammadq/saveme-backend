<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Accounts\AccountLookup;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Domain\Refusals\AccountNotActive;
use App\Modules\Billing\Domain\Refusals\InvalidDays;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotExtendable;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotFound;
use App\Modules\Billing\Domain\Refusals\SubscriptionNotRevocable;
use App\Modules\Billing\Domain\Refusals\SubscriptionRevoked;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * One person's account as an operator may see it (spec 021, US2; FR-021 to
 * FR-030a): state, plan, subscriptions and counts, never a name, an amount or
 * the stored address. Opening it is a budgeted, recorded read (once, in
 * `mount()`, R-3); an action only reloads the subscriptions and plan, so it
 * writes no second `account_viewed`. Grant, extend and revoke each ask for a
 * fresh second-factor proof and call `OperatorSubscriptions` with the
 * signed-in operator's id.
 */
class AccountPage extends BackOfficeComponent
{
    use RequiresRecentFactor;

    #[Locked]
    public string $userId = '';

    /** @var array<string, mixed> facts, counts, access and plan; scalars only (R-16) */
    public array $account = [];

    /** The address the operator typed, normalised, when they found the account by it. */
    public string $foundBy = '';

    public ?int $waitMinutes = null;

    public string $grantPlan = '';

    public string $grantDuration = '30';

    public string $grantDays = '';

    public string $grantReason = '';

    public string $extendSubscription = '';

    public string $extendDays = '';

    public string $extendReason = '';

    public string $revokeSubscription = '';

    public string $revokeReason = '';

    public function mount(string $user): void
    {
        $this->userId = $user;

        try {
            $snapshot = app(AccountLookup::class)->view($this->operatorId(), $user);
        } catch (LookupBudgetSpent $spent) {
            $this->waitMinutes = $spent->minutes;

            return;
        }

        abort_if($snapshot === null, 404);

        $this->account = $snapshot->toArray();

        $flashed = session()->pull(FindAccount::FLASH);

        if (is_array($flashed) && ($flashed['user'] ?? null) === $snapshot->facts->id && is_string($flashed['address'] ?? null)) {
            $this->foundBy = $flashed['address'];
        }
    }

    public function startGrant(): void
    {
        $this->assertActive();
        $this->reset('grantPlan', 'grantDuration', 'grantDays', 'grantReason');
        $this->resetErrorBag();
        $this->dispatch('admin-dialog-open', name: 'grant-access');
    }

    public function confirmGrant(): void
    {
        $this->assertActive();
        $this->resetErrorBag();

        if (! Str::isUuid($this->grantPlan)) {
            $this->failWith('grantPlan', 'plan_not_grantable');

            return;
        }

        $days = $this->grantDays();
        $reason = $this->reasonOf($this->grantReason, 'grantReason');

        if ($days === false || $reason === null) {
            return;
        }

        $this->dispatch('admin-dialog-close', name: 'grant-access');
        $this->requireRecentFactor('applyGrant', [$this->grantPlan, $days, $reason]);
    }

    public function startExtend(string $subscriptionId): void
    {
        $this->assertOwnSubscription($subscriptionId);
        $this->reset('extendDays', 'extendReason');
        $this->resetErrorBag();
        $this->extendSubscription = $subscriptionId;
        $this->dispatch('admin-dialog-open', name: 'extend-access');
    }

    public function confirmExtend(): void
    {
        $this->assertOwnSubscription($this->extendSubscription);
        $this->resetErrorBag();

        $days = $this->wholeDays($this->extendDays, 'extendDays');
        $reason = $this->reasonOf($this->extendReason, 'extendReason');

        if ($days === null || $reason === null) {
            return;
        }

        $this->dispatch('admin-dialog-close', name: 'extend-access');
        $this->requireRecentFactor('applyExtend', [$this->extendSubscription, $days, $reason]);
    }

    public function startRevoke(string $subscriptionId): void
    {
        $this->assertOwnSubscription($subscriptionId);
        $this->reset('revokeReason');
        $this->resetErrorBag();
        $this->revokeSubscription = $subscriptionId;
        $this->dispatch('admin-dialog-open', name: 'revoke-access');
    }

    public function confirmRevoke(): void
    {
        $this->assertOwnSubscription($this->revokeSubscription);
        $this->resetErrorBag();

        $reason = $this->reasonOf($this->revokeReason, 'revokeReason');

        if ($reason === null) {
            return;
        }

        $this->dispatch('admin-dialog-close', name: 'revoke-access');
        $this->requireRecentFactor('applyRevoke', [$this->revokeSubscription, $reason]);
    }

    protected function applyGrant(string $planId, ?int $days, string $reason): void
    {
        $this->run(fn () => app(OperatorSubscriptions::class)->grant($this->operatorId(), $this->userId, $planId, $days, $reason), 'granted');
    }

    protected function applyExtend(string $subscriptionId, int $days, string $reason): void
    {
        $this->run(fn () => app(OperatorSubscriptions::class)->extend($this->operatorId(), $subscriptionId, $days, $reason), 'extended');
    }

    protected function applyRevoke(string $subscriptionId, string $reason): void
    {
        $this->run(fn () => app(OperatorSubscriptions::class)->revoke($this->operatorId(), $subscriptionId, $reason), 'revoked');
    }

    /**
     * Runs an access command, maps its refusals to messages, and reloads the
     * subscriptions and plan (never the account read: no second recorded view).
     */
    private function run(callable $command, string $done): void
    {
        $error = null;

        try {
            $command();
        } catch (PlanNotGrantable) {
            $error = 'plan_not_grantable';
        } catch (AccountNotActive) {
            $error = 'account_not_active';
        } catch (SubscriptionNotExtendable) {
            $error = 'not_extendable';
        } catch (SubscriptionNotRevocable) {
            $error = 'not_revocable';
        } catch (SubscriptionRevoked) {
            $error = 'subscription_revoked';
        } catch (SubscriptionNotFound) {
            $error = 'subscription_not_found';
        } catch (InvalidDays) {
            $error = 'invalid_days';
        } catch (InvalidReason) {
            $error = 'invalid_reason';
        }

        $this->account = [...$this->account, ...app(AccountLookup::class)->refreshAccess($this->userId)];

        $error === null
            ? $this->dispatch('admin-toast', tone: 'success', message: __('admin::accounts.messages.'.$done))
            : $this->dispatch('admin-toast', tone: 'error', message: __('admin::accounts.errors.'.$error));
    }

    /**
     * The days a grant lasts: a number, null for life, or false after
     * recording the field error.
     */
    private function grantDays(): int|false|null
    {
        return match ($this->grantDuration) {
            '30', '90', '365' => (int) $this->grantDuration,
            'life' => null,
            'custom' => $this->wholeDays($this->grantDays, 'grantDays') ?? false,
            default => $this->failWith('grantDuration', 'invalid_days'),
        };
    }

    private function wholeDays(string $value, string $field): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,5}$/', $value) !== 1 || (int) $value < 1 || (int) $value > OperatorSubscriptions::MAX_DAYS) {
            $this->addError($field, __('admin::accounts.errors.invalid_days'));

            return null;
        }

        return (int) $value;
    }

    private function reasonOf(string $value, string $field): ?string
    {
        $reason = trim($value);
        $length = mb_strlen($reason);

        if ($length < 1 || $length > 500) {
            $this->addError($field, __('admin::accounts.errors.invalid_reason'));

            return null;
        }

        return $reason;
    }

    private function failWith(string $field, string $error): false
    {
        $this->addError($field, __('admin::accounts.errors.'.$error));

        return false;
    }

    private function assertActive(): void
    {
        abort_unless(($this->account['facts']['state'] ?? null) === 'active', 404);
    }

    private function assertOwnSubscription(string $subscriptionId): void
    {
        $ids = array_column($this->account['access']['subscriptions'] ?? [], 'id');

        abort_unless(in_array($subscriptionId, $ids, true), 404);
    }

    public function render(): View
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $state = $this->account['facts']['state'] ?? null;

        return view('admin::livewire.account-page', [
            'locale' => $locale,
            'grantable' => $state === 'active'
                ? array_map(fn (array $plan): array => ['value' => $plan['id'], 'label' => $plan['name'][$locale]], app(AccountLookup::class)->grantablePlans())
                : [],
        ])->layout('admin::page', [
            'title' => __('admin::accounts.page.title'),
            'breadcrumbs' => [
                ['label' => __('admin::accounts.title'), 'href' => route('admin.accounts')],
                ['label' => $this->userId],
            ],
        ]);
    }
}
