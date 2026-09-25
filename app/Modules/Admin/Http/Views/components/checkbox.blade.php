{{-- Checkbox (spec 006, FR-012): unchecked, checked, indeterminate, disabled. --}}
@props(['name' => null, 'label' => null, 'checked' => false, 'indeterminate' => false, 'disabled' => false, 'value' => '1', 'id' => null])

@php $id ??= $name ? $name.'-'.\Illuminate\Support\Str::slug((string) $value) : null; @endphp

<label @class(['inline-flex items-center gap-2.5 text-body font-medium', 'cursor-pointer text-ink' => ! $disabled, 'cursor-not-allowed text-ink-3' => $disabled])>
    <span class="relative inline-flex size-5 shrink-0">
        <input
            type="checkbox"
            @if ($id) id="{{ $id }}" @endif
            @if ($name) name="{{ $name }}" @endif
            value="{{ $value }}"
            @checked($checked)
            @disabled($disabled)
            @if ($indeterminate) aria-checked="mixed" x-data x-init="$el.indeterminate = true" @endif
            {{ $attributes->class([
                'peer size-5 cursor-[inherit] appearance-none rounded-md border-2 border-field bg-surface outline-none',
                'checked:border-accent checked:bg-accent indeterminate:border-accent indeterminate:bg-accent',
                'focus-visible:ring-4 focus-visible:ring-ring',
                'disabled:border-line disabled:bg-sunken',
                'border-accent bg-accent' => $indeterminate,
            ]) }}
        >
        <x-admin::icon name="check" :size="14" class="pointer-events-none invisible absolute inset-0.5 stroke-3 text-on-accent peer-checked:visible" />
        @if ($indeterminate)<x-admin::icon name="minus" :size="14" class="pointer-events-none absolute inset-0.5 stroke-3 text-on-accent peer-checked:invisible" />@endif
    </span>
    @if ($label)<span>{{ $label }}</span>@endif
</label>
