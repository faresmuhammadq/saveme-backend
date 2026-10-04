{{-- The outcome of a re-enrolment request; the half-finished sign-in has ended (spec 018, US4-1, US4-2, US4-10). --}}
<x-admin::layouts.auth :title="__('admin::auth.lost_device.heading')" :step="__('admin::auth.steps.lost_device')" :progress="2">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.lost_device.heading') }}</h1>

    <x-admin::alert :tone="$outcome->alreadyOpen ? 'warning' : 'success'" data-test="lost-device-outcome">
        @if ($outcome->alreadyOpen)
            {{ __('admin::auth.lost_device.already_waiting') }}
        @else
            {{ __('admin::auth.lost_device.sent') }}
        @endif
        <x-admin::instant :value="$outcome->expiresAt" />
    </x-admin::alert>

    @unless ($outcome->enoughApprovers)
        <x-admin::alert tone="warning" data-test="lost-device-few">{{ __('admin::auth.lost_device.few_operators') }}</x-admin::alert>
    @endunless

    <a href="{{ route('admin.sign-in') }}" class="text-label font-semibold text-accent hover:underline focus-ring-filled">{{ __('admin::auth.lost_device.to_sign_in') }}</a>
</x-admin::layouts.auth>
