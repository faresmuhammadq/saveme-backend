{{-- Step 2 of sign-in, recovery-code variant (spec 018, FR-023, US3). --}}
<x-admin::layouts.auth :title="__('admin::auth.recovery.heading')" :step="__('admin::auth.steps.recovery')" :progress="2">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.recovery.heading') }}</h1>

    @if ($codesLeft === 0)
        <x-admin::alert tone="warning" data-test="auth-error">{{ __('admin::auth.recovery.none_left') }}</x-admin::alert>
        <a href="{{ route('admin.sign-in.lost-device') }}" data-test="lost-device" class="text-label font-semibold text-accent hover:underline focus-ring-filled">{{ __('admin::auth.second_factor.lost_device') }}</a>
    @else
        <form method="POST" action="{{ route('admin.sign-in.recovery.submit') }}" class="flex flex-col gap-4">
            @csrf
            <x-admin::input
                name="recovery_code" required autofocus autocomplete="off" dir="ltr" mono
                :label="__('admin::auth.recovery.label')"
                :hint="__('admin::auth.recovery.format')"
                :error="session('admin.error')"
                data-test="recovery-input"
            />
            <x-admin::button type="submit" size="lg" class="w-full" data-test="code-submit">{{ __('admin::auth.second_factor.verify') }}</x-admin::button>
        </form>
    @endif

    <a href="{{ route('admin.sign-in.code') }}" class="text-label font-semibold text-accent hover:underline focus-ring-filled">{{ __('admin::auth.recovery.back') }}</a>
</x-admin::layouts.auth>
