{{--
    Stat tile (spec 006, FR-013). The delta's colour follows its meaning
    (good | bad | neutral), never its direction (up | down).
    Forms: default, delta, sparkline (bars), hero ring (ring 0–100),
    loading, suppressed (below the anonymity floor).
--}}
@props([
    'label',
    'value' => null,
    'delta' => null,
    'deltaMeaning' => 'neutral',
    'deltaDirection' => null,
    'caption' => null,
    'ring' => null,
    'ringLabel' => null,
    'bars' => [],
    'loading' => false,
    'suppressed' => false,
    'hero' => false,
])

@php
    $ringLength = 515;
    $ringFilled = $ring === null ? 0 : (int) intdiv(max(0, min(100, (int) $ring)) * $ringLength, 100);
    $maxBar = max(1, ...($bars ?: [1]));
    $deltaTone = match ($deltaMeaning) {
        'good' => 'text-positive-text',
        'bad' => 'text-over-text',
        default => 'text-ink-2',
    };
@endphp

<section @if ($loading) aria-busy="true" @endif {{ $attributes->class([
    'flex rounded-card border border-line bg-surface px-5.5 py-5',
    'items-center gap-5.5 rounded-hero' => $ring !== null,
    'flex-col' => $ring === null,
    'border-dashed border-field' => $suppressed,
]) }}>
    @if ($ring !== null && ! $loading)
        <div class="relative size-33 shrink-0">
            <svg viewBox="0 0 200 200" class="size-33 rtl:-scale-x-100" aria-hidden="true">
                <circle cx="100" cy="100" r="82" fill="none" stroke="currentColor" stroke-width="15" class="text-sunken" />
                <circle cx="100" cy="100" r="82" fill="none" stroke="currentColor" stroke-width="15" stroke-linecap="round" stroke-dasharray="{{ $ringFilled }} {{ $ringLength }}" transform="rotate(-90 100 100)" class="text-accent" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-h2 font-extrabold"><bdi dir="ltr">{{ $ringLabel ?? $ring.'%' }}</bdi></span>
            </div>
        </div>
    @endif
    <div class="flex min-w-0 flex-col">
        <span class="flex items-center gap-2 text-label font-semibold text-ink-2">{{ $label }}@isset($badge){{ $badge }}@endisset</span>
        @if ($loading)
            <x-admin::skeleton class="mt-2.5 w-3/5" />
            <x-admin::skeleton class="mt-2.5 w-4/5" />
        @elseif ($suppressed)
            <span class="mt-2 flex items-center gap-2 text-lead font-bold text-ink-3"><x-admin::icon name="private" />{{ __('admin::data.suppressed') }}</span>
            <span class="mt-1 text-caption text-ink-3">{{ __('admin::data.suppressed_hint') }}</span>
        @else
            <span @class(['mt-2 font-extrabold tracking-tight text-ink', 'text-numeral-xl leading-tight' => $hero, 'text-numeral-l' => ! $hero])>{{ $value ?? $slot }}</span>
            @if ($delta)
                <span class="mt-1 flex items-center gap-1 text-label font-bold {{ $deltaTone }}">
                    @if ($deltaDirection)<x-admin::icon :name="$deltaDirection === 'down' ? 'trend-down' : 'trend-up'" :size="16" />@endif
                    {{ $delta }}<span class="sr-only">, {{ __('admin::data.meaning.'.$deltaMeaning) }}</span>
                </span>
            @endif
            @if ($caption)<span class="mt-2.5 text-caption text-ink-3">{{ $caption }}</span>@endif
            @if ($bars)
                <div class="mt-3 flex h-7.5 items-end gap-1" aria-hidden="true">
                    @foreach ($bars as $bar)
                        <div @class(['flex-1 rounded-sm', 'bg-accent' => $loop->last, 'bg-sunken-2' => ! $loop->last]) style="height: {{ intdiv(max(0, (int) $bar) * 100, $maxBar) }}%"></div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</section>
