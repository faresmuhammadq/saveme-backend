{{--
    Badge (spec 006, FR-014). tone: accent | neutral | outline | count | danger-count | dot.
    A dot has no visible text; pass `label` for its accessible name.
--}}
@props(['tone' => 'neutral', 'icon' => null, 'label' => null])

@if ($tone === 'dot')
    <span {{ $attributes->class(['inline-block size-2.5 rounded-full bg-over ring-2 ring-surface']) }}>@if ($label)<span class="sr-only">{{ $label }}</span>@endif</span>
@else
    <span {{ $attributes->class([
        'inline-flex h-5.5 items-center gap-1.25 whitespace-nowrap',
        'rounded-badge px-2 text-caption' => ! in_array($tone, ['count', 'danger-count'], true),
        'min-w-5.5 justify-center rounded-full px-1.75 text-caption font-extrabold' => in_array($tone, ['count', 'danger-count'], true),
        'bg-accent-soft font-bold text-accent' => $tone === 'accent',
        'bg-sunken-2 font-bold text-ink-2' => $tone === 'neutral',
        'border border-field font-bold text-ink' => $tone === 'outline',
        'border border-line font-semibold text-ink-2' => $tone === 'subtle',
        'bg-accent text-on-accent' => $tone === 'count',
        'bg-over-text text-on-accent' => $tone === 'danger-count',
    ]) }}>@if ($icon)<x-admin::icon :name="$icon" :size="14" />@endif{{ $slot }}</span>
@endif
