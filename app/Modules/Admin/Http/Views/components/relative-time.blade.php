{{--
    Relative time (spec 006, FR-023): "4 minutes ago" under seven days, the
    absolute instant otherwise; the absolute instant is always in the title
    and accessible name.
--}}
@props(['value', 'timezone' => null, 'thresholdDays' => 7])

@php
    $temporal = app(\App\Modules\Admin\Http\Presenters\TemporalPresenter::class);
    $zone = $timezone ?? config('admin.timezone');
    $locale = app()->getLocale();
    $absolute = $temporal->instant($value, $zone, $locale);
    $shown = $temporal->relative($value, \Carbon\CarbonImmutable::now(), $zone, $locale, (int) $thresholdDays);
@endphp

<time datetime="{{ $temporal->machineInstant($value) }}" title="{{ $absolute }}" aria-label="{{ $absolute }}" {{ $attributes->class(['whitespace-nowrap underline decoration-ink-3 decoration-dotted underline-offset-4']) }}>{{ $shown }}</time>
