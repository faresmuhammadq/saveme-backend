{{--
    Select (spec 006, FR-012; WAI-ARIA APG select-only combobox).
    options: list of ['value' => …, 'label' => …, 'meta' => ?string].
    A hidden input carries the value for forms and wire:model.
    Keyboard behaviour lives in adminSelect (Assets/admin.js).
--}}
@props(['name', 'options', 'value' => null, 'id' => null, 'label' => null, 'hint' => null, 'error' => null, 'required' => false, 'placeholder' => null, 'disabled' => false, 'open' => false])

@php
    $id ??= $name;
    $listbox = "{$id}-listbox";
    $selected = collect($options)->first(fn (array $option): bool => (string) $option['value'] === (string) $value);
    $invalid = filled($error);
    $describedBy = collect([$error ? "{$id}-error" : null, $hint ? "{$id}-hint" : null])->filter()->implode(' ');
@endphp

<x-admin::field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
<div
    x-data="adminSelect({ value: @js($selected['value'] ?? null), open: {{ $open ? 'true' : 'false' }} })"
    x-on:click.outside="close(false)"
    x-on:keydown.escape.prevent.stop="close(true)"
    {{ $attributes->whereDoesntStartWith('wire:model')->class(['relative']) }}
>
    <input type="hidden" name="{{ $name }}" x-bind:value="value" value="{{ $selected['value'] ?? '' }}" {{ $attributes->whereStartsWith('wire:model') }}>
    <button
        type="button"
        id="{{ $id }}"
        role="combobox"
        aria-haspopup="listbox"
        aria-controls="{{ $listbox }}"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-bind:aria-expanded="open.toString()"
        x-bind:aria-activedescendant="activeId"
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @disabled($disabled)
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown="onKeydown($event)"
        @class([
            'flex h-10.5 w-full items-center justify-between gap-2 rounded-control border bg-surface px-3.5 text-start text-body outline-none disabled:cursor-not-allowed disabled:border-line disabled:bg-sunken disabled:text-ink-3',
            'border-field focus-visible:border-accent focus-visible:ring-4 focus-visible:ring-ring' => ! $invalid && ! $open,
            'border-accent ring-4 ring-ring' => $open,
            'border-over-text ring-1 ring-over-text' => $invalid,
        ])
    >
        <span x-ref="label" @class(['truncate', 'text-ink-3' => ! $selected])>{{ $selected['label'] ?? ($placeholder ?? __('admin::forms.select_placeholder')) }}</span>
        <x-admin::icon :name="$open ? 'chevron-up' : 'chevron-down'" class="text-ink-2" />
    </button>
    <ul
        id="{{ $listbox }}"
        role="listbox"
        tabindex="-1"
        @if ($label) aria-labelledby="{{ $id }}" @endif
        x-ref="listbox"
        x-show="open"
        @unless ($open) x-cloak @endunless
        class="absolute start-0 top-full z-30 mt-1.5 flex w-full flex-col rounded-tile border border-line bg-surface p-1.5 shadow-overlay"
    >
        @foreach ($options as $option)
            @php $isSelected = (string) $option['value'] === (string) ($selected['value'] ?? null); @endphp
            <li
                id="{{ $listbox }}-{{ $loop->index }}"
                role="option"
                aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                data-value="{{ $option['value'] }}"
                data-label="{{ $option['label'] }}"
                x-on:click="choose($el)"
                x-bind:class="activeId === $el.id ? 'bg-sunken' : ''"
                @class([
                    'flex h-9 cursor-pointer items-center gap-2 rounded-lg px-2.5 text-body',
                    'bg-accent-soft font-bold text-accent' => $isSelected,
                    'text-ink' => ! $isSelected,
                ])
            >
                <x-admin::icon name="check" :size="16" :class="$isSelected ? '' : 'invisible'" />
                <span class="flex-1 truncate">{{ $option['label'] }}</span>
                @isset($option['meta'])<span @class(['text-label', 'text-ink-3' => ! $isSelected])>{{ $option['meta'] }}</span>@endisset
            </li>
        @endforeach
    </ul>
</div>
</x-admin::field>
