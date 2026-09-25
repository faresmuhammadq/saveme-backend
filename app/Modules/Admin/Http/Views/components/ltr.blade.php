{{-- Keeps a value left-to-right inside right-to-left text (FR-033). --}}
<bdi dir="ltr" {{ $attributes }}>{{ $slot }}</bdi>
