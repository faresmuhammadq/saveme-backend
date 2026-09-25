{{--
    Segmented control (spec 006, FR-011): a small radio group.
    options: list of ['value' => …, 'label' => …]
--}}
@props(['options', 'value' => null, 'name', 'label' => null])

<div role="radiogroup" @if ($label) aria-label="{{ $label }}" @endif {{ $attributes->whereDoesntStartWith('wire:model')->class(['inline-flex overflow-hidden rounded-control border border-field']) }}>
    @foreach ($options as $option)
        @php $checked = (string) $option['value'] === (string) $value; @endphp
        <label @class([
            'relative inline-flex h-9.5 cursor-pointer items-center px-3.5 text-label has-focus-visible:z-10 has-focus-visible:ring-4 has-focus-visible:ring-ring',
            'border-s border-line' => ! $loop->first,
            'bg-surface font-semibold text-ink-2 hover:bg-sunken has-checked:bg-accent-soft has-checked:font-bold has-checked:text-accent',
        ])>
            <input type="radio" class="sr-only" name="{{ $name }}" value="{{ $option['value'] }}" @checked($checked) {{ $attributes->whereStartsWith('wire:model') }}>
            <span @if (isset($option['lang'])) lang="{{ $option['lang'] }}" @endif>{{ $option['label'] }}</span>
        </label>
    @endforeach
</div>
