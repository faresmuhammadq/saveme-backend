<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Enrolment\BeginEnrolment;
use App\Modules\Admin\Application\Enrolment\ReplaceAuthenticator as Replace;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Links a new authenticator from the security page (spec 018, FR-025). The
 * setup key is never kept in component state: it is read again from the
 * operator's pending secret on every render, and only after a fresh
 * second-factor proof has marked the page ready.
 */
class ReplaceAuthenticator extends BackOfficeComponent
{
    use RequiresRecentFactor;

    #[Locked]
    public bool $ready = false;

    public string $oneTimeCode = '';

    public function mount(): void
    {
        $this->requireRecentFactor('prepare');
    }

    public function confirm(): void
    {
        $code = $this->oneTimeCode;
        $this->reset('oneTimeCode');
        $this->resetErrorBag();

        if (! $this->ready || ! app(OperatorSession::class)->hasRecentFactor()) {
            $this->ready = false;
            $this->requireRecentFactor('prepare');

            return;
        }

        try {
            app(Replace::class)->handle($this->operatorId(), $code);
        } catch (SecondFactorInvalid) {
            $this->addError('oneTimeCode', __('admin::auth.enrol.invalid'));

            return;
        } catch (OperatorLocked $locked) {
            $this->addError('oneTimeCode', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));

            return;
        } catch (PendingSignInExpired) {
            $this->ready = false;
            $this->requireRecentFactor('prepare');

            return;
        }

        session()->flash('admin.status', __('admin::security.toasts.authenticator_replaced'));
        $this->redirectRoute('admin.security');
    }

    protected function prepare(): void
    {
        $this->ready = true;
    }

    public function render(): View
    {
        return view('admin::livewire.replace-authenticator', [
            'setup' => $this->ready ? app(BeginEnrolment::class)->handle($this->operatorId()) : null,
        ])->layout('admin::page', ['title' => __('admin::security.replace.title')]);
    }
}
