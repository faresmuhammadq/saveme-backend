{{--
    Tabs (spec 006, FR-015). style: underline | pill.
    items: list of ['key', 'label', 'count' => ?int, 'href' => ?string, 'wire' => ?string, 'test' => ?string (data-test hook)].
    With hrefs they are navigation links (aria-current); otherwise an in-page
    tablist whose `wire` names the Livewire method that switches to the key.
--}}
@props(['items', 'current', 'style' => 'underline', 'label' => null])

@php $isNavigation = collect($items)->contains(fn (array $item): bool => isset($item['href'])); @endphp

<{{ $isNavigation ? 'nav' : 'div' }}
    @if ($isNavigation) aria-label="{{ $label }}" @else role="tablist" @if ($label) aria-label="{{ $label }}" @endif @endif
    {{ $attributes->class([
        'flex gap-6 overflow-x-auto border-b border-line' => $style === 'underline',
        'inline-flex gap-1 rounded-tile bg-sunken p-1' => $style === 'pill',
    ]) }}
>
    @foreach ($items as $item)
        @php
            $active = $item['key'] === $current;
            $classes = [
                'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-body focus-ring-filled',
                'h-10 border-b-2 -mb-px' => $style === 'underline',
                'border-accent font-bold text-ink' => $style === 'underline' && $active,
                'border-transparent font-semibold text-ink-2 hover:text-ink' => $style === 'underline' && ! $active,
                'h-8 rounded-lg px-3.5' => $style === 'pill',
                'bg-surface font-bold text-ink shadow-raised' => $style === 'pill' && $active,
                'font-semibold text-ink-2 hover:text-ink' => $style === 'pill' && ! $active,
            ];
        @endphp
        @if ($isNavigation)
            <a href="{{ $item['href'] }}" @isset($item['test']) data-test="{{ $item['test'] }}" @endisset @if ($active) aria-current="page" @endif @class($classes)>
                {{ $item['label'] }}
                @isset($item['count'])<x-admin::badge :tone="$active ? 'count' : 'neutral'" class="rounded-full">{{ $item['count'] }}</x-admin::badge>@endisset
            </a>
        @else
            <button type="button" role="tab" @isset($item['test']) data-test="{{ $item['test'] }}" @endisset aria-selected="{{ $active ? 'true' : 'false' }}" tabindex="{{ $active ? '0' : '-1' }}" @isset($item['wire']) wire:click="{{ $item['wire'] }}('{{ $item['key'] }}')" @endisset @class($classes)>
                {{ $item['label'] }}
                @isset($item['count'])<x-admin::badge :tone="$active ? 'count' : 'neutral'" class="rounded-full">{{ $item['count'] }}</x-admin::badge>@endisset
            </button>
        @endif
    @endforeach
</{{ $isNavigation ? 'nav' : 'div' }}>
