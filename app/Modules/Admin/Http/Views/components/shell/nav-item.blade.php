{{-- Sidebar item (spec 006, FR-006, FR-008). The current page is marked for sighted and screen-reader users. --}}
@props(['href', 'icon', 'label', 'current' => false, 'badge' => null])

<a
    href="{{ $href }}"
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'flex h-9.5 items-center gap-2.5 rounded-control px-2.5 text-body no-underline focus-ring-filled rtl:h-10',
        'bg-accent-soft font-bold text-accent' => $current,
        'font-medium text-ink-2 hover:bg-sunken hover:text-ink' => ! $current,
    ]) }}
>
    <x-admin::icon :name="$icon" />
    <span class="flex-1 truncate">{{ $label }}</span>
    @if ($badge)<x-admin::badge tone="count">{{ $badge }}</x-admin::badge>@endif
</a>
