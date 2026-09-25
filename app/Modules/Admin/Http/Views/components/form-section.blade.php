{{--
    Form section (spec 006, FR-012): a titled group of fields with an optional
    description column and footer actions.
--}}
@props(['title', 'description' => null])

@php $headingId = 'section-'.\Illuminate\Support\Str::random(8); @endphp

<section aria-labelledby="{{ $headingId }}" {{ $attributes->class(['overflow-hidden rounded-card border border-line bg-surface']) }}>
    <div class="grid grid-cols-3 gap-7 p-6">
        <div class="col-span-1">
            <h3 id="{{ $headingId }}" class="text-h3 font-bold text-ink">{{ $title }}</h3>
            @if ($description)<p class="mt-1.5 text-label text-ink-2">{{ $description }}</p>@endif
        </div>
        <div class="col-span-2 grid grid-cols-2 gap-x-4.5 gap-y-4">{{ $slot }}</div>
    </div>
    @isset($footer)
        <div class="flex justify-end gap-2.5 border-t border-line bg-sunken px-6 py-3.5">{{ $footer }}</div>
    @endisset
</section>
