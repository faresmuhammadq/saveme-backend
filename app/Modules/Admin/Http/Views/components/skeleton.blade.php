{{-- Skeleton placeholder (spec 006, FR-014). shape: line | circle | tile. The owning region sets aria-busy. --}}
@props(['shape' => 'line', 'lines' => 1])

@if ($shape === 'line')
    <div aria-hidden="true" {{ $attributes->class(['flex flex-col gap-2']) }}>
        @for ($i = 0; $i < $lines; $i++)
            <div @class(['h-3 animate-shimmer rounded-badge bg-sunken-2', 'w-4/5' => $i === $lines - 1 && $lines > 1])></div>
        @endfor
    </div>
@else
    <div aria-hidden="true" {{ $attributes->class([
        'animate-shimmer bg-sunken-2',
        'size-11 rounded-full' => $shape === 'circle',
        'h-22 rounded-tile' => $shape === 'tile',
    ]) }}></div>
@endif
