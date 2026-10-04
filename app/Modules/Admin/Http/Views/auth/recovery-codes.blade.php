{{--
    Save your recovery codes (spec 018, FR-022, R-11). The codes are in this
    one response and nowhere else: a reload shows the form without them.
--}}
<x-admin::layouts.auth :title="__('admin::auth.codes.heading')" :step="__('admin::auth.steps.codes')">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.codes.heading') }}</h1>

    @if ($codes !== null)
        <p class="text-body text-ink-2">{{ __('admin::auth.codes.explanation') }}</p>
        <x-admin::recovery-code-list :codes="$codes" />
    @else
        <x-admin::alert tone="warning">{{ __('admin::auth.codes.gone') }}</x-admin::alert>
    @endif

    <form method="POST" action="{{ route('admin.enrol.done') }}" class="flex flex-col gap-4">
        @csrf
        <x-admin::checkbox name="saved" :label="__('admin::auth.codes.saved')" data-test="recovery-saved" required />
        @if (session('admin.error'))
            <p role="alert" aria-live="assertive" data-test="auth-error" class="flex items-center gap-1.5 text-caption font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ session('admin.error') }}</p>
        @endif
        <x-admin::button type="submit" size="lg" class="w-full" data-test="recovery-continue">{{ __('admin::auth.codes.continue') }}</x-admin::button>
    </form>
</x-admin::layouts.auth>
