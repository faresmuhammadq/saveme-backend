{{--
    Filter chips (spec 006, FR-013). applied: list of ['key', 'label'].
    remove-action: wire method taking the key. The default slot holds the
    "add filter" control(s).
--}}
@props(['applied' => [], 'removeAction' => null])

<div {{ $attributes->class(['flex flex-wrap items-center gap-2']) }}>
    @foreach ($applied as $filter)
        <span class="inline-flex h-8.5 items-center gap-1.5 rounded-full border border-accent bg-accent-soft ps-3 pe-1.5 text-label font-bold text-accent">
            {{ $filter['label'] }}
            <button
                type="button"
                aria-label="{{ __('admin::data.remove_filter', ['filter' => $filter['label']]) }}"
                @if ($removeAction) wire:click="{{ $removeAction }}('{{ $filter['key'] }}')" @endif
                class="inline-flex size-5.5 items-center justify-center rounded-full hover:bg-surface focus-ring-filled"
            ><x-admin::icon name="close" :size="14" /></button>
        </span>
    @endforeach
    {{ $slot }}
</div>
