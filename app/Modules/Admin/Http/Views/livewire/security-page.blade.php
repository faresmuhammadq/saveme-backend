<div class="flex flex-col gap-5">
    @if (session('admin.status'))
        <x-admin::alert tone="success" data-test="status">{{ session('admin.status') }}</x-admin::alert>
    @endif

    @if ($operator->recoveryCodesLeft <= \App\Modules\Admin\Http\Livewire\Overview::LOW_CODES)
        <x-admin::alert tone="warning" data-test="low-codes">{{ trans_choice('admin::auth.overview.low_codes', $operator->recoveryCodesLeft, ['count' => $operator->recoveryCodesLeft]) }}</x-admin::alert>
    @endif

    <x-admin::card :title="__('admin::security.facts')">
        <x-admin::description-list>
            <dt class="col-span-1 border-t border-line py-2.5 text-ink-2">{{ __('admin::security.authenticator') }}</dt>
            <dd class="col-span-2 border-t border-line py-2.5 font-semibold text-ink" data-test="security-authenticator">
                @if ($operator->enrolledAt)
                    {{ __('admin::security.linked') }} <x-admin::instant :value="$operator->enrolledAt" />
                @endif
            </dd>
            <dt class="col-span-1 border-t border-line py-2.5 text-ink-2">{{ __('admin::security.recovery_codes') }}</dt>
            <dd class="col-span-2 border-t border-line py-2.5 font-semibold text-ink" data-test="security-codes">
                {{ __('admin::security.codes_left', ['count' => $operator->recoveryCodesLeft, 'total' => 10]) }}
            </dd>
            <dt class="col-span-1 border-t border-line py-2.5 text-ink-2">{{ __('admin::security.last_sign_in') }}</dt>
            <dd class="col-span-2 border-t border-line py-2.5 font-semibold text-ink" data-test="security-last-sign-in">
                @if ($operator->lastSignedInAt)<x-admin::instant :value="$operator->lastSignedInAt" />@else —@endif
            </dd>
        </x-admin::description-list>
    </x-admin::card>

    <x-admin::card :title="__('admin::security.actions')">
        <div class="flex flex-wrap gap-2.5">
            <x-admin::button variant="secondary" icon="lock" wire:click="startPasswordChange" data-test="security-change-password">{{ __('admin::security.change_password') }}</x-admin::button>
            <x-admin::button variant="secondary" icon="refresh" x-on:click="$dispatch('admin-dialog-open', { name: 'confirm-regenerate' })" data-test="security-regenerate">{{ __('admin::security.regenerate') }}</x-admin::button>
            <x-admin::button variant="secondary" icon="device" wire:click="replaceAuthenticator" data-test="security-replace">{{ __('admin::security.replace_button') }}</x-admin::button>
        </div>
    </x-admin::card>

    @if ($issuedCodes !== [])
        <x-admin::card :title="__('admin::security.new_codes.title')" :subtitle="__('admin::auth.codes.explanation')" data-test="new-codes">
            <x-admin::recovery-code-list :codes="$issuedCodes" />
            <x-slot:footer>
                <x-admin::button variant="secondary" wire:click="$refresh" data-test="new-codes-done">{{ __('admin::security.new_codes.done') }}</x-admin::button>
            </x-slot:footer>
        </x-admin::card>
    @endif

    <x-admin::dialog name="change-password" :title="__('admin::security.password.title')" icon="lock" tone="positive" size="sm">
        <form class="flex flex-col gap-4" wire:submit="changePassword" id="change-password-form">
            <x-admin::input name="currentPassword" type="password" autocomplete="current-password" required
                :label="__('admin::security.password.current')" :error="$errors->first('currentPassword')"
                wire:model="currentPassword" data-test="security-current-password" />
            <x-admin::input name="newPassword" type="password" autocomplete="new-password" required
                :label="__('admin::auth.password.new')" :hint="__('admin::auth.password.rules')" :error="$errors->first('newPassword')"
                wire:model="newPassword" data-test="new-password" />
            <x-admin::input name="newPasswordConfirmation" type="password" autocomplete="new-password" required
                :label="__('admin::auth.password.confirm')"
                wire:model="newPasswordConfirmation" data-test="new-password-confirm" />
        </form>
        <x-slot:footer>
            <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button type="submit" form="change-password-form" data-test="security-password-submit">{{ __('admin::auth.password.save') }}</x-admin::button>
        </x-slot:footer>
    </x-admin::dialog>

    <x-admin::dialog name="confirm-regenerate" :title="__('admin::security.regenerate_confirm.title')" :description="__('admin::security.regenerate_confirm.body')" icon="refresh" tone="danger" size="sm" role="alertdialog">
        <x-slot:footer>
            <x-admin::button variant="ghost" x-on:click="hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button variant="danger" data-test="security-regenerate-confirm" x-on:click="hide(); $wire.regenerate()">{{ __('admin::security.regenerate_confirm.confirm') }}</x-admin::button>
        </x-slot:footer>
    </x-admin::dialog>

    <x-admin::factor-prompt />
</div>
