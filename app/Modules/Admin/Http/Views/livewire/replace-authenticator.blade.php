<div class="flex max-w-xl flex-col gap-5">
    <x-admin::alert tone="info">{{ __('admin::security.replace.keeps_working') }}</x-admin::alert>

    @if ($setup)
        <x-admin::card>
            <ol class="flex flex-col gap-4 text-body text-ink-2">
                <li>{{ __('admin::auth.enrol.step_open') }}</li>
                <li class="flex flex-col gap-3">
                    <span>{{ __('admin::auth.enrol.step_add') }}</span>
                    <x-admin::setup-key :key="$setup->keyGrouped" :link="$setup->link" :label="$setup->label" />
                </li>
                <li>{{ __('admin::auth.enrol.step_code') }}</li>
            </ol>
            <form wire:submit="confirm" class="mt-4 flex flex-col gap-4">
                <x-admin::input name="oneTimeCode" autocomplete="one-time-code" inputmode="numeric" required dir="ltr" mono
                    :label="__('admin::auth.second_factor.code_label')" :error="$errors->first('oneTimeCode')"
                    wire:model="oneTimeCode" data-test="enrol-code" />
                <div><x-admin::button type="submit" data-test="replace-confirm">{{ __('admin::auth.enrol.link_authenticator') }}</x-admin::button></div>
            </form>
        </x-admin::card>
    @endif

    <x-admin::factor-prompt />
</div>
