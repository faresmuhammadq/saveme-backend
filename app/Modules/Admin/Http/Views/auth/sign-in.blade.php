{{-- Step 1 of sign-in: email and password (spec 018, FR-010; contracts/routes-and-screens.md). --}}
<x-admin::layouts.auth :title="__('admin::auth.sign_in.heading')" :step="__('admin::auth.steps.password')" :progress="1">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.sign_in.heading') }}</h1>

    <form method="POST" action="{{ route('admin.sign-in.submit') }}" class="flex flex-col gap-4">
        @csrf
        <x-admin::input
            name="email" type="email" required autofocus autocomplete="username"
            :label="__('admin::auth.sign_in.email')"
            value="{{ old('email') }}"
            data-test="sign-in-email"
        />
        <x-admin::input
            name="password" type="password" required autocomplete="current-password"
            :label="__('admin::auth.sign_in.password')"
            data-test="sign-in-password"
        />
        @if (session('admin.error'))
            <p role="alert" aria-live="assertive" data-test="auth-error" class="flex items-center gap-1.5 text-caption font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ session('admin.error') }}</p>
        @endif
        <x-admin::button type="submit" size="lg" class="w-full" data-test="sign-in-submit">{{ __('admin::auth.sign_in.continue') }}</x-admin::button>
    </form>
</x-admin::layouts.auth>
