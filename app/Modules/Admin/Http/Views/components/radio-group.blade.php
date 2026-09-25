{{--
    Radio group (spec 006, FR-012). style: card | plain.
    options: list of ['value' => …, 'label' => …, 'description' => ?string].
--}}
@props(['name', 'legend', 'options', 'value' => null, 'style' => 'card', 'disabled' => false])

<fieldset {{ $attributes->whereDoesntStartWith('wire:model')->class(['flex flex-col gap-2']) }} @disabled($disabled)>
    <legend class="mb-2 text-label font-semibold text-ink">{{ $legend }}</legend>
    <div @class(['flex gap-2.5', 'flex-col' => $style === 'plain'])>
        @foreach ($options as $option)
            {{-- Selection styling follows the live :checked state, so it updates on click without a round trip. --}}
            @php $checked = (string) $option['value'] === (string) $value; @endphp
            <label @class([
                'group flex cursor-pointer items-center gap-2.5 has-focus-visible:ring-4 has-focus-visible:ring-ring',
                'flex-1 rounded-tile px-3.5 py-3' => $style === 'card',
                'border border-field bg-surface hover:bg-sunken has-checked:border-accent has-checked:bg-accent-soft has-checked:ring-1 has-checked:ring-accent' => $style === 'card',
                'rounded-control' => $style === 'plain',
            ])>
                <input
                    type="radio" name="{{ $name }}" value="{{ $option['value'] }}" @checked($checked)
                    {{ $attributes->whereStartsWith('wire:model') }}
                    class="size-4.5 shrink-0 appearance-none rounded-full border-2 border-field bg-surface outline-none checked:border-5 checked:border-accent"
                >
                <span class="flex flex-col">
                    <span class="text-body font-medium group-has-checked:font-bold">{{ $option['label'] }}</span>
                    @isset($option['description'])<span class="text-caption text-ink-3">{{ $option['description'] }}</span>@endisset
                </span>
            </label>
        @endforeach
    </div>
</fieldset>
