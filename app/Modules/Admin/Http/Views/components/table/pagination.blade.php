{{--
    Cursor pagination (spec 006, FR-013, FR-015): newer / older, no page
    numbers. A null URL or action disables that direction.
--}}
@props(['summary' => null, 'previousUrl' => null, 'nextUrl' => null, 'previousAction' => null, 'nextAction' => null])

@php
    $hasPrevious = $previousUrl || $previousAction;
    $hasNext = $nextUrl || $nextAction;
    $link = 'inline-flex h-8.5 items-center gap-1 rounded-control border text-label font-semibold focus-ring-filled';
@endphp

<nav aria-label="{{ __('admin::data.pagination') }}" {{ $attributes->class(['flex w-full items-center justify-between gap-4']) }}>
    <span class="text-label text-ink-2">{{ $summary }}</span>
    <div class="flex gap-2">
        @if ($previousUrl)
            <a href="{{ $previousUrl }}" rel="prev" data-test="page-newer" class="{{ $link }} border-field bg-surface ps-2 pe-3 text-ink"><x-admin::icon name="chevron-start" :size="16" />{{ __('admin::data.newer') }}</a>
        @else
            <button type="button" @if ($previousAction) wire:click="{{ $previousAction }}" @else disabled @endif data-test="page-newer" class="{{ $link }} ps-2 pe-3 {{ $hasPrevious ? 'border-field bg-surface text-ink' : 'border-line bg-sunken text-ink-3' }}"><x-admin::icon name="chevron-start" :size="16" />{{ __('admin::data.newer') }}</button>
        @endif
        @if ($nextUrl)
            <a href="{{ $nextUrl }}" rel="next" data-test="page-older" class="{{ $link }} border-field bg-surface ps-3 pe-2 text-ink">{{ __('admin::data.older') }}<x-admin::icon name="chevron-end" :size="16" /></a>
        @else
            <button type="button" @if ($nextAction) wire:click="{{ $nextAction }}" @else disabled @endif data-test="page-older" class="{{ $link }} ps-3 pe-2 {{ $hasNext ? 'border-field bg-surface text-ink' : 'border-line bg-sunken text-ink-3' }}">{{ __('admin::data.older') }}<x-admin::icon name="chevron-end" :size="16" /></button>
        @endif
    </div>
</nav>
