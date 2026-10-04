{{-- Choose a new password: forced at first sign-in and after a reset (spec 018, FR-004, FR-062). --}}
<x-admin::layouts.auth :title="__('admin::auth.password.heading')" :step="__('admin::auth.steps.new_password')">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.password.heading') }}</h1>

    <form method="POST" action="{{ route('admin.password.new.submit') }}" class="flex flex-col gap-4">
        @csrf
        <x-admin::input
            name="password" type="password" required autofocus autocomplete="new-password"
            :label="__('admin::auth.password.new')"
            :hint="__('admin::auth.password.rules')"
            data-test="new-password"
        />
        <x-admin::input
            name="password_confirmation" type="password" required autocomplete="new-password"
            :label="__('admin::auth.password.confirm')"
            data-test="new-password-confirm"
        />
        @if (session('admin.error'))
            <p role="alert" aria-live="assertive" data-test="auth-error" class="flex items-center gap-1.5 text-caption font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ session('admin.error') }}</p>
        @endif
        <x-admin::button type="submit" size="lg" class="w-full">{{ __('admin::auth.password.save') }}</x-admin::button>
    </form>
</x-admin::layouts.auth>
