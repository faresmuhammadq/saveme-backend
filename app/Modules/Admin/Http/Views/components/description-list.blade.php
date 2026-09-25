{{-- Description list (spec 006, FR-013). items: list of ['term', 'value'], or <dt>/<dd> pairs in the slot. --}}
@props(['items' => []])

<dl {{ $attributes->class(['grid grid-cols-3 text-body']) }}>
    @foreach ($items as $item)
        <dt class="col-span-1 border-t border-line py-2.5 text-ink-2">{{ $item['term'] }}</dt>
        <dd class="col-span-2 border-t border-line py-2.5 font-semibold text-ink">{{ $item['value'] }}</dd>
    @endforeach
    {{ $slot }}
</dl>
