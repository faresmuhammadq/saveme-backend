{{--
    Toggle (spec 006, FR-012): role="switch". The knob travels toward the
    end when on, so it mirrors in right-to-left by itself.
--}}
@props(['name' => null, 'label', 'description' => null, 'checked' => false, 'disabled' => false, 'id' => null])

@php $id ??= ($name ?? 'toggle').'-'.\Illuminate\Support\Str::random(6); @endphp

<div x-data="{ on: {{ $checked ? 'true' : 'false' }} }" {{ $attributes->whereDoesntStartWith('wire:model')->class(['flex items-center justify-between gap-4']) }}>
    <div @class(['flex flex-col', 'text-ink-3' => $disabled])>
        <span id="{{ $id }}-label" class="text-body font-semibold">{{ $label }}</span>
        @if ($description)<span id="{{ $id }}-description" class="text-caption text-ink-3">{{ $description }}</span>@endif
    </div>
    @if ($name)<input type="hidden" name="{{ $name }}" x-bind:value="on ? '1' : '0'" value="{{ $checked ? '1' : '0' }}" {{ $attributes->whereStartsWith('wire:model') }}>@endif
    <button
        type="button"
        role="switch"
        aria-checked="{{ $checked ? 'true' : 'false' }}"
        x-bind:aria-checked="on.toString()"
        aria-labelledby="{{ $id }}-label"
        @if ($description) aria-describedby="{{ $id }}-description" @endif
        @disabled($disabled)
        x-on:click="on = ! on"
        x-bind:class="on ? 'bg-accent justify-end' : 'bg-field justify-start'"
        @class([
            'flex h-6.5 w-11 shrink-0 items-center rounded-full p-0.75 transition-colors focus-ring-filled disabled:cursor-not-allowed disabled:bg-sunken-2',
            $checked ? 'bg-accent justify-end' : 'bg-field justify-start',
        ])
    >
        <span class="size-5 rounded-full bg-surface shadow-raised"></span>
    </button>
</div>
