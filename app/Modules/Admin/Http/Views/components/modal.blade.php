{{-- Modal (spec 006, FR-014): a medium dialog. --}}
<x-admin::dialog size="md" {{ $attributes }}>
    {{ $slot }}
    <x-slot:footer>{{ $footer ?? '' }}</x-slot:footer>
</x-admin::dialog>
