<div class="flex flex-col gap-5">
    @if ($recoveryCodesLeft <= \App\Modules\Admin\Http\Livewire\Overview::LOW_CODES)
        <x-admin::alert tone="warning" :dismissible="true" data-test="low-codes">
            {{ trans_choice('admin::auth.overview.low_codes', $recoveryCodesLeft, ['count' => $recoveryCodesLeft]) }}
            <x-slot:action>
                <x-admin::button variant="secondary" size="sm" :href="route('admin.security')">{{ __('admin::shell.nav.security') }}</x-admin::button>
            </x-slot:action>
        </x-admin::alert>
    @endif

    @if (session('admin.notice'))
        <x-admin::alert tone="success" data-test="auth-notice">{{ session('admin.notice') }}</x-admin::alert>
    @endif

    <x-admin::card>
        <x-admin::empty-state :title="__('admin::auth.overview.empty_title')" icon="overview">
            {{ __('admin::auth.overview.empty_body') }}
        </x-admin::empty-state>
    </x-admin::card>
</div>
