{{-- "Lost your device?": ask two other operators to approve a re-enrolment (spec 018, FR-030, US4). --}}
<x-admin::layouts.auth :title="__('admin::auth.lost_device.heading')" :step="__('admin::auth.steps.lost_device')" :progress="2">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.lost_device.heading') }}</h1>
    <p class="text-body leading-normal text-ink-2">{{ __('admin::auth.lost_device.explanation', ['hours' => config('admin.auth.reenrolment_hours')]) }}</p>

    @if (session('admin.error'))
        <p role="alert" aria-live="assertive" data-test="auth-error" class="flex items-center gap-1.5 text-caption font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ session('admin.error') }}</p>
    @endif

    <form method="POST" action="{{ route('admin.sign-in.lost-device.submit') }}" class="flex flex-col gap-3">
        @csrf
        <x-admin::button type="submit" size="lg" class="w-full" data-test="lost-device-submit">{{ __('admin::auth.lost_device.ask') }}</x-admin::button>
    </form>
    <a href="{{ route('admin.sign-in.code') }}" class="text-label font-semibold text-accent hover:underline focus-ring-filled">{{ __('admin::auth.recovery.back') }}</a>
</x-admin::layouts.auth>
