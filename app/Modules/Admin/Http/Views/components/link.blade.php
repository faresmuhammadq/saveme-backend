{{-- Link (spec 006, FR-011). variant: default | quiet · external opens a new tab. --}}
@props(['href', 'variant' => 'default', 'external' => false])

<a
    href="{{ $href }}"
    @if ($external) target="_blank" rel="noopener noreferrer" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1 rounded-badge font-semibold focus-ring-filled',
        'text-accent no-underline hover:text-accent-hover hover:underline underline-offset-4' => $variant === 'default',
        'text-ink-2 underline underline-offset-4 hover:text-ink' => $variant === 'quiet',
    ]) }}
>{{ $slot }}@if ($external)<x-admin::icon name="external" :size="14" /><span class="sr-only">{{ __('admin::actions.opens_new_tab') }}</span>@endif</a>
