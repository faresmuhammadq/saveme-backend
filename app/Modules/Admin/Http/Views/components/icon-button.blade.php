{{--
    Icon-only button (spec 006, FR-004, FR-011). `label` is required: it is the
    accessible name. variant: ghost | outline | filled | danger · size: sm | md
--}}
@props(['icon', 'label', 'variant' => 'ghost', 'size' => 'md', 'type' => 'button', 'disabled' => false, 'previewState' => null])

@php
    if (blank($label ?? null)) {
        throw new \InvalidArgumentException('<x-admin::icon-button> needs a label: it is the accessible name.');
    }
@endphp

<button
    type="{{ $type }}"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    @disabled($disabled)
    {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center rounded-control transition-colors',
        'size-8' => $size === 'sm',
        'size-9' => $size !== 'sm',
        'hover:bg-sunken hover:text-ink focus-ring-filled' => $variant === 'ghost',
        'bg-transparent text-ink-2' => $variant === 'ghost' && $previewState !== 'hover',
        'bg-sunken text-ink' => $variant === 'ghost' && $previewState === 'hover',
        'border border-field bg-surface text-ink-2 hover:bg-sunken focus-ring' => $variant === 'outline',
        'bg-accent text-on-accent hover:bg-accent-hover focus-ring-filled' => $variant === 'filled',
        'bg-over-soft text-over-text hover:brightness-95 focus-ring-filled' => $variant === 'danger',
        'ring-4 ring-ring' => $previewState === 'focus',
        'disabled:cursor-not-allowed disabled:border-transparent disabled:bg-sunken-2 disabled:text-ink-3',
    ]) }}
><x-admin::icon :name="$icon" :size="$size === 'sm' ? 16 : 18" /></button>
