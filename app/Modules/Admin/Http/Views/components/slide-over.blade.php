{{-- Slide-over (spec 006, FR-014): a dialog along the end edge. Slots: default, footer. --}}
<x-admin::dialog placement="end" {{ $attributes }}>
    {{ $slot }}
    <x-slot:footer>{{ $footer ?? '' }}</x-slot:footer>
</x-admin::dialog>
