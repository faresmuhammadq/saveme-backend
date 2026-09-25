{{--
    Status pill (spec 006, FR-003, FR-014). tone: positive | pending | over | info | neutral.
    The text carries the meaning; the dot is decorative.
--}}
@props(['tone' => 'neutral', 'size' => 'md'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full font-bold whitespace-nowrap',
    'h-7 px-2.75 text-label' => $size === 'md',
    'h-6 px-2.25 text-caption' => $size === 'sm',
    'bg-positive-soft text-positive-text' => $tone === 'positive',
    'bg-pending-soft text-pending-text' => $tone === 'pending',
    'bg-over-soft text-over-text' => $tone === 'over',
    'bg-accent-soft text-accent' => $tone === 'info',
    'bg-sunken-2 text-ink-2' => $tone === 'neutral',
]) }}><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $slot }}</span>
