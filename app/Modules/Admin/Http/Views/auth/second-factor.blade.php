{{-- Step 2 of sign-in: the six-digit code (spec 018, FR-010, FR-092; design board "Screen · Operator sign-in"). --}}
<x-admin::layouts.auth :title="__('admin::auth.second_factor.heading')" :step="__('admin::auth.steps.second_factor')" :progress="2">
    <div class="flex flex-col gap-1.5">
        <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.second_factor.heading') }}</h1>
        <div class="flex flex-wrap items-baseline gap-x-1.5 text-body text-ink-2">
            <span>{{ __('admin::auth.second_factor.from_app') }} <x-admin::ltr class="font-semibold text-ink">{{ $email }}</x-admin::ltr></span>
            <form method="POST" action="{{ route('admin.sign-in.restart') }}">
                @csrf
                <button type="submit" data-test="not-you" class="font-semibold text-accent hover:underline focus-ring-filled">{{ __('admin::auth.second_factor.not_you') }}</button>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.sign-in.code.submit') }}" class="flex flex-col gap-4">
        @csrf
        <x-admin::code-input :error="session('admin.error')" />

        <p class="flex items-center gap-2 text-label text-ink-2" x-data="adminCountdown({{ $serverTime }})">
            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke-width="3" class="stroke-sunken-2" /><circle cx="10" cy="10" r="8" fill="none" stroke-width="3" stroke-dasharray="30 50" transform="rotate(-90 10 10)" stroke-linecap="round" class="stroke-accent" /></svg>
            <span x-text="@js(__('admin::auth.second_factor.countdown', ['seconds' => ':seconds'])).replace(':seconds', seconds)">{{ __('admin::auth.second_factor.countdown', ['seconds' => 30]) }}</span>
        </p>

        @if ($showClockHint)
            <p class="text-caption text-ink-2">{{ __('admin::auth.second_factor.clock_hint') }}</p>
        @endif

        <x-admin::button type="submit" size="lg" class="w-full" data-test="code-submit">{{ __('admin::auth.second_factor.verify') }}</x-admin::button>
    </form>

    <div class="flex justify-between text-label font-semibold">
        <a href="{{ route('admin.sign-in.recovery') }}" data-test="use-recovery" class="text-accent hover:underline focus-ring-filled">{{ __('admin::auth.second_factor.use_recovery') }}</a>
        <a href="{{ route('admin.sign-in.lost-device') }}" data-test="lost-device" class="text-ink-2 hover:underline focus-ring-filled">{{ __('admin::auth.second_factor.lost_device') }}</a>
    </div>

    <div class="mt-2 flex gap-3 rounded-tile bg-sunken px-4 py-3.5 text-label leading-normal text-ink-2">
        <x-admin::icon name="key" class="mt-px text-accent" />
        <span>{{ __('admin::auth.second_factor.hint') }} {!! __('admin::auth.second_factor.hint_codes', ['remaining' => '<strong class="text-ink">'.e((string) $codesLeft).'</strong>']) !!}</span>
    </div>
</x-admin::layouts.auth>
