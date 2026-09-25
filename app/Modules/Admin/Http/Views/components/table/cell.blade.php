{{-- Table cell (spec 006, FR-013). align: start | end · mono for identifiers · strong for the lead column. --}}
@props(['align' => 'start', 'mono' => false, 'strong' => false, 'muted' => false])

<td {{ $attributes->class([
    'px-3 text-body',
    'text-start' => $align === 'start',
    'text-end' => $align === 'end',
    'font-mono text-mono text-ink-2' => $mono,
    'font-semibold text-ink' => $strong,
    'text-ink-2' => $muted,
]) }}>{{ $slot }}</td>
