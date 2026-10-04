<?php

namespace App\Modules\Admin\Http\Livewire;

use App\Modules\Admin\Application\Credentials\ChangeOperatorPassword;
use App\Modules\Admin\Application\Enrolment\RegenerateRecoveryCodes;
use App\Modules\Admin\Domain\Operators\Refusals\InvalidOperatorCredentials;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PasswordRuleBroken;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\Concerns\RequiresRecentFactor;
use Illuminate\Contracts\View\View;

/**
 * An operator's own sign-in security (spec 018, US5; FR-024, FR-025, FR-060 to
 * FR-062): the facts, and three actions, each behind a fresh second-factor
 * proof. The password fields are cleared on every submit, and freshly issued
 * recovery codes live in a protected property, so neither a password nor a
 * code ever travels back in a component snapshot.
 */
class SecurityPage extends BackOfficeComponent
{
    use RequiresRecentFactor;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /** @var list<string> shown once, in the response that made them; protected state is never serialised */
    protected array $issuedCodes = [];

    public function startPasswordChange(): void
    {
        $this->requireRecentFactor('openPasswordDialog');
    }

    public function changePassword(): void
    {
        $current = $this->currentPassword;
        $new = $this->newPassword;
        $confirmation = $this->newPasswordConfirmation;
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->resetErrorBag();

        if (! app(OperatorSession::class)->hasRecentFactor()) {
            $this->dispatch('admin-dialog-close', name: 'change-password');
            $this->requireRecentFactor('openPasswordDialog');

            return;
        }

        if ($new !== $confirmation) {
            $this->addError('newPassword', __('admin::auth.password.mismatch'));

            return;
        }

        try {
            $result = app(ChangeOperatorPassword::class)->handle($this->operatorId(), $new, $current);
        } catch (PasswordRuleBroken $broken) {
            $this->addError('newPassword', __('admin::auth.password.errors.'.$broken->violations[0]));

            return;
        } catch (InvalidOperatorCredentials) {
            $this->addError('currentPassword', __('admin::security.password.current_wrong'));

            return;
        } catch (OperatorLocked $locked) {
            $this->addError('currentPassword', trans_choice('admin::auth.locked', $locked->minutes(), ['minutes' => $locked->minutes()]));

            return;
        } catch (PendingSignInExpired) {
            abort(401);
        }

        app(OperatorSession::class)->adoptEpoch($result->epoch);

        $this->dispatch('admin-dialog-close', name: 'change-password');
        $this->dispatch('admin-toast', tone: 'success', message: __('admin::security.toasts.password_changed'));
    }

    public function regenerate(): void
    {
        $this->requireRecentFactor('regenerateCodes');
    }

    public function replaceAuthenticator(): void
    {
        $this->redirectRoute('admin.security.authenticator');
    }

    protected function openPasswordDialog(): void
    {
        $this->dispatch('admin-dialog-open', name: 'change-password');
    }

    protected function regenerateCodes(): void
    {
        $this->issuedCodes = app(RegenerateRecoveryCodes::class)->handle($this->operatorId())->codes;

        $this->dispatch('admin-toast', tone: 'success', message: __('admin::security.toasts.codes_regenerated'));
    }

    public function render(): View
    {
        return view('admin::livewire.security-page', [
            'operator' => $this->operator(),
            'issuedCodes' => $this->issuedCodes,
        ])->layout('admin::page', ['title' => __('admin::security.title')]);
    }
}
