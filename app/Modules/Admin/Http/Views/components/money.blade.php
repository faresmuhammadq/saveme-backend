{{--
    Money (spec 006, FR-020, FR-021). From integer minor units and an ISO 4217
    code; never floating point. `compact` is for aggregate tiles only and keeps
    the full value in the title and accessible name. The number stays
    left-to-right; in Arabic the symbol follows the Arabic reading order.
--}}
@props(['minor', 'currency', 'compact' => false])

@php
    $money = app(\App\Modules\Admin\Http\Presenters\MoneyPresenter::class);
    $locale = app()->getLocale();
    $full = $money->format((int) $minor, $currency, $locale);
    $shown = $compact ? $money->compact((int) $minor, $currency, $locale) : $full;
    [$number, $words] = $locale === 'ar' ? array_pad(explode(' ', $shown, 2), 2, '') : [$shown, ''];
@endphp

<data value="{{ $money->machineValue((int) $minor, $currency) }}" @if ($compact) title="{{ $full }}" aria-label="{{ $full }}" @endif {{ $attributes->class(['whitespace-nowrap tabular-nums']) }}><bdi dir="ltr">{{ $number }}</bdi>@if ($words !== '') {{ $words }}@endif</data>
