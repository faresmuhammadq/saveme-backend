{{-- "Add filter" chip button (spec 006, FR-013). --}}
<button type="button" {{ $attributes->class(['inline-flex h-8.5 items-center gap-1.5 rounded-full border border-dashed border-field px-3 text-label font-semibold text-ink-2 hover:bg-sunken focus-ring-filled']) }}>
    <x-admin::icon name="add" :size="14" />{{ $slot }}
</button>
