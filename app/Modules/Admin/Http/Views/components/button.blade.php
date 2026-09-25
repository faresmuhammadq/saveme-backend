{{--
    Button (spec 006, FR-011; contracts/components.md → Actions).
    variant: primary | secondary | danger | ghost · size: sm | md | lg
    preview-state (gallery only): hover | pressed | focus
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'icon' => null,
    'iconEnd' => null,
    'type' => 'button',
    'loading' => false,
    'disabled' => false,
    'href' => null,
    'previewState' => null,
])

@php
    // Each variant's resting background is separate, so a gallery preview can replace it
    // rather than compete with it in the stylesheet.
    $variants = [
        'primary' => ['bg' => 'bg-accent', 'rest' => 'text-on-accent hover:bg-accent-hover active:bg-accent-pressed focus-ring-filled', 'hover' => 'bg-accent-hover', 'pressed' => 'bg-accent-pressed'],
        'secondary' => ['bg' => 'bg-surface', 'rest' => 'border border-field text-ink hover:bg-sunken active:bg-sunken-2 focus-ring', 'hover' => 'bg-sunken', 'pressed' => 'bg-sunken-2'],
        'danger' => ['bg' => 'bg-over-text', 'rest' => 'text-on-accent hover:brightness-90 active:brightness-75 focus-ring-filled', 'hover' => 'bg-over-text brightness-90', 'pressed' => 'bg-over-text brightness-75'],
        'ghost' => ['bg' => 'bg-transparent', 'rest' => 'text-accent hover:bg-accent-soft active:bg-sunken-2 focus-ring-filled', 'hover' => 'bg-accent-soft', 'pressed' => 'bg-sunken-2'],
    ];
    $sizes = [
        'sm' => 'h-8 gap-1.5 px-3 text-label',
        'md' => 'h-10 gap-2 px-4 text-body',
        'lg' => 'h-12 gap-2 px-5 text-lead font-bold',
    ];
    $variantClasses = $variants[$variant] ?? $variants['primary'];
    $iconSize = $size === 'sm' ? 16 : 18;

    $classes = [
        'inline-flex items-center justify-center rounded-control font-semibold whitespace-nowrap select-none transition-colors',
        $sizes[$size] ?? $sizes['md'],
        $variantClasses['rest'],
        in_array($previewState, ['hover', 'pressed'], true) ? $variantClasses[$previewState] : $variantClasses['bg'],
        'ring-4 ring-ring' => $previewState === 'focus',
        'cursor-progress opacity-80 pointer-events-none' => $loading,
        'disabled:cursor-not-allowed disabled:border-transparent disabled:bg-sunken-2 disabled:text-ink-3 disabled:brightness-100',
        'aria-disabled:pointer-events-none aria-disabled:border-transparent aria-disabled:bg-sunken-2 aria-disabled:text-ink-3',
    ];
@endphp

@if ($href)
    <a
        href="{{ $disabled ? '#' : $href }}"
        @if ($disabled) aria-disabled="true" tabindex="-1" @endif
        @if ($loading) aria-busy="true" @endif
        data-variant="{{ $variant }}"
        {{ $attributes->class($classes) }}
    >
        @if ($loading)<x-admin::spinner />@elseif ($icon)<x-admin::icon :name="$icon" :size="$iconSize" />@endif
        <span>{{ $slot }}</span>
        @if ($iconEnd)<x-admin::icon :name="$iconEnd" :size="$iconSize" />@endif
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($disabled)
        @if ($loading) aria-busy="true" @endif
        data-variant="{{ $variant }}"
        {{ $attributes->class($classes) }}
    >
        @if ($loading)<x-admin::spinner />@elseif ($icon)<x-admin::icon :name="$icon" :size="$iconSize" />@endif
        <span>{{ $slot }}</span>
        @if ($iconEnd)<x-admin::icon :name="$iconEnd" :size="$iconSize" />@endif
    </button>
@endif
