{{--
    Calendar-date picker (spec 006, FR-012; APG date picker dialog).
    The value is a calendar date (YYYY-MM-DD) and never shifts with timezone
    (FR-022). The grid is drawn by adminDatePicker with Western digits
    (FR-034); a native date input would show browser-locale digits.
--}}
@props(['name', 'value' => null, 'id' => null, 'label' => null, 'hint' => null, 'error' => null, 'required' => false, 'min' => null, 'max' => null, 'open' => false])

@php
    $id ??= str_replace(['[', ']'], ['-', ''], $name);
    $months = __('admin::forms.months');
    $monthLabel = $value ? $months[(int) substr($value, 5, 2) - 1].' '.substr($value, 0, 4) : '';
    $describedBy = collect([$error ? "{$id}-error" : null, $hint ? "{$id}-hint" : null])->filter()->implode(' ');
@endphp

<x-admin::field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
<div
    x-data="adminDatePicker({ value: @js($value), open: {{ $open ? 'true' : 'false' }}, months: @js($months), min: @js($min), max: @js($max) })"
    x-on:click.outside="close(false)"
    x-on:keydown.escape.prevent.stop="close(true)"
    {{ $attributes->whereDoesntStartWith('wire:model')->class(['relative']) }}
>
    <input type="hidden" name="{{ $name }}" x-bind:value="value" value="{{ $value }}" {{ $attributes->whereStartsWith('wire:model') }}>
    <button
        type="button"
        id="{{ $id }}"
        aria-haspopup="dialog"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        x-bind:aria-expanded="open.toString()"
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        x-ref="trigger"
        x-on:click="toggle()"
        @class([
            'flex h-10.5 w-full items-center gap-2.5 rounded-control border bg-surface px-3 text-start text-body text-ink outline-none',
            'border-field focus-visible:border-accent focus-visible:ring-4 focus-visible:ring-ring' => ! $open && ! $error,
            'border-accent ring-4 ring-ring' => $open,
            'border-over-text ring-1 ring-over-text' => $error,
        ])
    >
        <x-admin::icon name="date" class="text-ink-2" />
        <bdi dir="ltr" data-date-value class="whitespace-nowrap" x-text="value || ''">{{ $value }}</bdi>
    </button>
    <div
        role="dialog"
        aria-modal="false"
        aria-label="{{ __('admin::forms.date_picker') }}"
        x-ref="dialog"
        x-show="open"
        @unless ($open) x-cloak @endunless
        class="absolute start-0 top-full z-30 mt-1.5 w-75 rounded-tile border border-line bg-surface p-3.5 shadow-overlay"
    >
        <div class="mb-2.5 flex items-center justify-between">
            <x-admin::icon-button icon="chevron-start" :label="__('admin::forms.previous_month')" size="sm" x-on:click="shiftMonth(-1)" />
            <span class="text-body font-bold" aria-live="polite" x-text="monthLabel">{{ $monthLabel }}</span>
            <x-admin::icon-button icon="chevron-end" :label="__('admin::forms.next_month')" size="sm" x-on:click="shiftMonth(1)" />
        </div>
        <table role="grid" class="w-full border-collapse text-center" x-on:keydown="onGridKeydown($event)">
            <thead>
                <tr>
                    @foreach (__('admin::forms.weekdays_short') as $weekday)
                        <th scope="col" class="py-1 text-overline font-bold text-ink-3">{{ $weekday }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <template x-for="(week, w) in weeks" :key="w">
                    <tr>
                        <template x-for="day in week" :key="day.iso">
                            <td class="p-0.5">
                                <button
                                    type="button"
                                    class="h-8 w-full rounded-lg text-label font-medium outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                    x-bind:class="dayClass(day)"
                                    x-bind:tabindex="day.iso === focused ? 0 : -1"
                                    x-bind:aria-selected="(day.iso === value).toString()"
                                    x-bind:aria-disabled="day.disabled.toString()"
                                    x-bind:data-iso="day.iso"
                                    x-on:click="pick(day)"
                                    x-text="day.n"
                                ></button>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
</x-admin::field>
