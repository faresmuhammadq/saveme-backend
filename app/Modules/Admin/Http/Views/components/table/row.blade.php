{{--
    Table row (spec 006, FR-013). With `select-value`, a leading checkbox is
    rendered and selection state shown. preview-state: hover (gallery).
--}}
@props(['selected' => false, 'selectValue' => null, 'selectLabel' => null, 'previewState' => null])

<tr
    @if ($selected) aria-selected="true" @endif
    {{ $attributes->class([
        'h-13 border-b border-line last:border-b-0',
        'bg-accent-soft' => $selected,
        'bg-sunken' => ! $selected && $previewState === 'hover',
        'hover:bg-sunken' => ! $selected,
    ]) }}
>
    @if ($selectValue !== null)
        <td class="px-2">
            <span class="flex justify-center">
                <x-admin::checkbox :checked="$selected" :value="$selectValue" :aria-label="$selectLabel ?? __('admin::data.select_row')" />
            </span>
        </td>
    @endif
    {{ $slot }}
</tr>
