{{-- Empty state (spec 006, FR-014). tone: neutral | positive | search. --}}
@props(['title', 'icon' => null, 'tone' => 'neutral', 'level' => 2])

@php
    $icon ??= ['positive' => 'check', 'search' => 'search'][$tone] ?? 'info';
    $level = max(2, min(6, (int) $level));
@endphp

<div {{ $attributes->class(['flex flex-col items-center gap-2.5 px-7 py-9 text-center']) }}>
    <div @class([
        'flex size-13 items-center justify-center rounded-tile',
        'bg-positive-soft text-positive-text' => $tone === 'positive',
        'bg-accent-soft text-accent' => $tone === 'search',
        'bg-sunken-2 text-ink-2' => $tone === 'neutral',
    ])><x-admin::icon :name="$icon" :size="24" /></div>
    <h{{ $level }} class="text-h3 font-bold text-ink">{{ $title }}</h{{ $level }}>
    @if (trim($slot) !== '')<div class="max-w-72 text-label text-ink-2">{{ $slot }}</div>@endif
    @isset($action)<div class="mt-1">{{ $action }}</div>@endisset
</div>
