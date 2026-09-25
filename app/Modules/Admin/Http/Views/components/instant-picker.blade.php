{{--
    Instant picker (spec 006, FR-012, FR-023). Picks a date and a time in the
    operator's timezone and submits them as {name}[date], {name}[time] and
    {name}[timezone]; the owning feature converts to UTC. The hint shows the
    zone and the stored UTC value.
--}}
@props(['name', 'value' => null, 'timezone' => null, 'label' => null, 'error' => null, 'required' => false, 'open' => false])

@php
    $timezone ??= config('admin.timezone');
    $id = str_replace(['[', ']'], ['-', ''], $name);
    $local = $value ? \Carbon\CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone) : null;
@endphp

<fieldset {{ $attributes->class(['flex flex-col gap-1.5']) }} aria-describedby="{{ $id }}-hint">
    @if ($label)
        <legend class="mb-1.5 text-label font-semibold text-ink">{{ $label }}@if ($required)<span class="ms-0.5 text-over-text" aria-hidden="true">*</span><span class="sr-only"> ({{ __('admin::forms.required') }})</span>@endif</legend>
    @endif
    <div class="flex gap-2">
        <x-admin::date-picker :name="$name.'[date]'" :value="$local?->format('Y-m-d')" :open="$open" class="min-w-40 flex-1" />
        <label class="flex h-10.5 w-28 items-center gap-2 rounded-control border border-field bg-surface px-3 text-body focus-within:border-accent focus-within:ring-4 focus-within:ring-ring">
            <x-admin::icon name="instant" class="text-ink-2" />
            <span class="sr-only">{{ __('admin::forms.time') }}</span>
            <input type="text" name="{{ $name }}[time]" value="{{ $local?->format('H:i') }}" dir="ltr" inputmode="numeric" x-data x-mask="99:99" placeholder="00:00" class="w-full min-w-0 bg-transparent outline-none rtl:text-end">
        </label>
        <input type="hidden" name="{{ $name }}[timezone]" value="{{ $timezone }}">
    </div>
    @if ($error)
        <p class="flex items-center gap-1.5 text-caption font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ $error }}</p>
    @endif
    <p id="{{ $id }}-hint" class="text-caption text-ink-3">
        {{ __('admin::values.timezone', ['zone' => $timezone]) }}@if ($value) · {{ __('admin::values.stored_utc') }} <bdi dir="ltr" class="font-mono">{{ \Carbon\CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString() }}</bdi>@endif
    </p>
</fieldset>
