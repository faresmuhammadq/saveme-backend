{{--
    Text input (spec 006, FR-012). type: text | email | password | url.
    Email, URL and code values stay left-to-right (FR-033); pass dir="ltr"
    for codes. With `label`, the input wraps itself in <x-admin::field>.
    Slots: prefix, suffix (add-ons inside the border).
--}}
@props([
    'name',
    'id' => null,
    'type' => 'text',
    'label' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'invalid' => false,
    'dir' => null,
    'mono' => false,
    'previewState' => null,
])

@php
    $id ??= $name;
    $invalid = $invalid || filled($error);
    $dir ??= in_array($type, ['email', 'url'], true) ? 'ltr' : null;
    $describedBy = collect([$error ? "{$id}-error" : null, $hint ? "{$id}-hint" : null])->filter()->implode(' ');
    $hasAddons = isset($prefix) || isset($suffix);

    $frame = [
        'rounded-control border bg-surface text-body text-ink transition-shadow',
        'border-field focus-within:border-accent focus-within:ring-4 focus-within:ring-ring' => ! $invalid,
        'border-over-text ring-1 ring-over-text' => $invalid,
        'border-accent ring-4 ring-ring' => $previewState === 'focus',
        'has-disabled:border-line has-disabled:bg-sunken has-disabled:text-ink-3',
    ];
    $control = [
        'h-10 w-full min-w-0 bg-transparent px-3.5 outline-none placeholder:text-ink-3 disabled:cursor-not-allowed read-only:text-ink-2',
        'font-mono text-mono' => $mono,
        'rtl:text-end' => $dir === 'ltr',
    ];
@endphp

<x-admin::field :for="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
    @if ($hasAddons)
        <div @class(array_merge($frame, ['flex items-stretch overflow-hidden']))>
            @isset($prefix)<span class="flex items-center gap-2 border-e border-line bg-sunken px-3 text-label font-bold text-ink-2">{{ $prefix }}</span>@endisset
            <input
                type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
                @if ($dir) dir="{{ $dir }}" @endif
                @if ($invalid) aria-invalid="true" @endif
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                @required($required)
                {{ $attributes->class($control) }}
            >
            @isset($suffix)<span class="flex items-center gap-2 pe-2 text-ink-3">{{ $suffix }}</span>@endisset
        </div>
    @else
        <input
            type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
            @if ($dir) dir="{{ $dir }}" @endif
            @if ($invalid) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @required($required)
            {{ $attributes->class(array_merge($frame, $control)) }}
        >
    @endif
</x-admin::field>
