{{--
    Menu button (spec 006, FR-011; WAI-ARIA APG menu button).
    Arrow keys, Home/End, Enter, Escape and typeahead are handled by the
    adminMenu Alpine component (Assets/admin.js). `open` renders it open
    (gallery). align: start | end.
--}}
@props(['label', 'icon' => null, 'variant' => 'secondary', 'iconOnly' => false, 'align' => 'start', 'open' => false])

@php $id = 'menu-'.\Illuminate\Support\Str::random(8); @endphp

<div x-data="adminMenu({{ $open ? 'true' : 'false' }})" x-on:keydown.escape.prevent.stop="close(true)" x-on:click.outside="close(false)" {{ $attributes->class(['relative inline-block']) }}>
    @if ($iconOnly)
        <x-admin::icon-button
            :icon="$icon ?? 'more'"
            :label="$label"
            :variant="$variant === 'secondary' ? 'ghost' : $variant"
            x-ref="trigger"
            aria-haspopup="menu"
            aria-controls="{{ $id }}"
            x-bind:aria-expanded="open.toString()"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            x-on:click="toggle()"
            x-on:keydown.down.prevent="openAndFocus('first')"
            x-on:keydown.up.prevent="openAndFocus('last')"
        />
    @else
        <x-admin::button
            :variant="$variant"
            :icon="$icon"
            :icon-end="$open ? 'chevron-up' : 'chevron-down'"
            x-ref="trigger"
            aria-haspopup="menu"
            aria-controls="{{ $id }}"
            x-bind:aria-expanded="open.toString()"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            x-on:click="toggle()"
            x-on:keydown.down.prevent="openAndFocus('first')"
            x-on:keydown.up.prevent="openAndFocus('last')"
        >{{ $label }}</x-admin::button>
    @endif

    <div
        id="{{ $id }}"
        role="menu"
        aria-label="{{ $label }}"
        x-ref="panel"
        x-show="open"
        @unless ($open) x-cloak @endunless
        x-transition.opacity.duration.100ms
        x-on:keydown="onKeydown($event)"
        @class([
            'absolute top-full z-30 mt-1.5 flex w-max min-w-60 max-w-100 flex-col rounded-tile border border-line bg-surface p-1.5 shadow-overlay',
            'start-0' => $align === 'start',
            'end-0' => $align === 'end',
        ])
    >{{ $slot }}</div>
</div>
