{{-- Instant (spec 006, FR-023): stored UTC, shown in the operator's timezone with the zone. --}}
@props(['value', 'timezone' => null])

@php
    $temporal = app(\App\Modules\Admin\Http\Presenters\TemporalPresenter::class);
    $shown = $temporal->instant($value, $timezone ?? config('admin.timezone'), app()->getLocale());
@endphp

<time datetime="{{ $temporal->machineInstant($value) }}" {{ $attributes->class(['whitespace-nowrap tabular-nums']) }}>{{ $shown }}</time>
