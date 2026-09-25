{{-- Card (spec 006, FR-013). padding: default | none · elevation: flat | raised. Slots: actions, footer. --}}
@props(['title' => null, 'subtitle' => null, 'padding' => 'default', 'elevation' => 'flat', 'level' => 2])

@php $headingId = $title ? 'card-'.\Illuminate\Support\Str::random(8) : null; @endphp

<{{ $title ? 'section' : 'div' }} @if ($headingId) aria-labelledby="{{ $headingId }}" @endif {{ $attributes->class([
    'overflow-hidden rounded-card bg-surface',
    'border border-line' => $elevation === 'flat',
    'shadow-raised' => $elevation === 'raised',
]) }}>
    @if ($title || isset($actions))
        <div @class(['flex items-start justify-between gap-4', 'px-5.5 pt-5' => $padding !== 'none', 'border-b border-line px-5 py-4' => $padding === 'none'])>
            <div>
                @if ($title)<h{{ $level }} id="{{ $headingId }}" class="text-h3 font-bold text-ink">{{ $title }}</h{{ $level }}>@endif
                @if ($subtitle)<p class="mt-0.5 text-label text-ink-3">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['px-5.5 pt-3 pb-5' => $padding !== 'none' && $title, 'p-5.5' => $padding !== 'none' && ! $title])>{{ $slot }}</div>
    @isset($footer)
        <div class="flex items-center justify-end gap-2.5 border-t border-line bg-sunken px-5.5 py-3.5">{{ $footer }}</div>
    @endisset
</{{ $title ? 'section' : 'div' }}>
