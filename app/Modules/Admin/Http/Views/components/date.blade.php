{{-- Calendar date (spec 006, FR-022): shown as stored, never timezone-shifted. --}}
@props(['value'])

@php $date = app(\App\Modules\Admin\Http\Presenters\TemporalPresenter::class)->calendarDate($value); @endphp

<time datetime="{{ $date }}" {{ $attributes->class(['whitespace-nowrap tabular-nums']) }}><bdi dir="ltr">{{ $date }}</bdi></time>
