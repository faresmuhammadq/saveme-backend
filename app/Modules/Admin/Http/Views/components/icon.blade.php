{{--
    Icon (spec 006, FR-004, FR-032). Decorative unless `label` is given.
    Direction-bearing icons mirror in right-to-left.
--}}
@props(['name', 'size' => 18, 'label' => null])

@php
    $icon = app(\App\Modules\Admin\Http\Icons\IconRegistry::class)->get($name);
    $sizeClass = match ((int) $size) {
        14 => 'size-3.5',
        16 => 'size-4',
        20 => 'size-5',
        24 => 'size-6',
        default => 'size-4.5',
    };
@endphp

<svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    focusable="false"
    data-icon="{{ $name }}"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif
    {{ $attributes->class([$sizeClass, 'shrink-0', 'rtl:-scale-x-100' => $icon['mirrors']]) }}
>{!! $icon['paths'] !!}</svg>
