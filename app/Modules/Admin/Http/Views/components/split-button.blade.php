{{--
    Split button (spec 006, FR-011): a primary action plus a menu of related
    actions. Slots: default (primary label), `menu` (menu items).
--}}
@props(['variant' => 'primary', 'menuLabel', 'open' => false])

<div x-data="adminMenu({{ $open ? 'true' : 'false' }})" x-on:keydown.escape.prevent.stop="close(true)" x-on:click.outside="close(false)" class="relative inline-flex">
    <x-admin::button :variant="$variant" {{ $attributes->class(['rounded-e-none']) }}>{{ $slot }}</x-admin::button>
    <x-admin::icon-button
        icon="chevron-down"
        :label="$menuLabel"
        variant="filled"
        class="h-10 w-10 rounded-s-none border-s border-on-accent/35"
        x-ref="trigger"
        aria-haspopup="menu"
        x-bind:aria-expanded="open.toString()"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-on:click="toggle()"
        x-on:keydown.down.prevent="openAndFocus('first')"
    />
    <div role="menu" aria-label="{{ $menuLabel }}" x-ref="panel" x-show="open" @unless ($open) x-cloak @endunless x-on:keydown="onKeydown($event)" class="absolute end-0 top-full z-30 mt-1.5 flex min-w-60 flex-col rounded-tile border border-line bg-surface p-1.5 shadow-overlay">{{ $menu }}</div>
</div>
