{{--
    Search field (spec 006, FR-012). The shortcut key (default "/") focuses it
    from anywhere on the page, unless the operator is typing elsewhere.
--}}
@props(['name' => 'q', 'label' => null, 'placeholder' => null, 'shortcut' => '/', 'value' => null])

@php $label ??= __('admin::forms.search'); @endphp

<div role="search" {{ $attributes->whereDoesntStartWith('wire:model')->class(['relative']) }}>
    <label class="flex h-10 items-center gap-2 rounded-control border border-field bg-surface px-3 text-ink-3 focus-within:border-accent focus-within:ring-4 focus-within:ring-ring">
        <x-admin::icon name="search" :size="16" />
        <span class="sr-only">{{ $label }}@if ($shortcut) — {{ __('admin::forms.shortcut', ['key' => $shortcut]) }}@endif</span>
        <input
            type="search"
            name="{{ $name }}"
            value="{{ $value }}"
            placeholder="{{ $placeholder ?? $label }}"
            @if ($shortcut)
                aria-keyshortcuts="{{ $shortcut }}"
                x-data
                x-on:keydown.slash.window="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { $event.preventDefault(); $el.focus() }"
            @endif
            {{ $attributes->whereStartsWith('wire:model') }}
            class="w-full min-w-0 bg-transparent text-body text-ink outline-none placeholder:text-ink-3 [&::-webkit-search-cancel-button]:hidden"
        >
        @if ($shortcut)<kbd class="rounded-badge border border-line px-1.5 font-mono text-caption font-bold">{{ $shortcut }}</kbd>@endif
    </label>
</div>
