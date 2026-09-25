{{--
    Textarea (spec 006, FR-012). With `max`, shows a live character counter
    (characters, not bytes) announced politely.
--}}
@props(['name', 'id' => null, 'label' => null, 'hint' => null, 'error' => null, 'required' => false, 'invalid' => false, 'max' => null, 'rows' => 3, 'value' => ''])

@php
    $id ??= $name;
    $invalid = $invalid || filled($error);
    $describedBy = collect([$error ? "{$id}-error" : null, $hint ? "{$id}-hint" : null, $max ? "{$id}-count" : null])->filter()->implode(' ');
@endphp

<div x-data="{ count: {{ mb_strlen((string) $value) }} }" class="flex flex-col gap-1.5">
    @if ($label)
        <label for="{{ $id }}" class="text-label font-semibold text-ink">{{ $label }}@if ($required)<span class="ms-0.5 text-over-text" aria-hidden="true">*</span><span class="sr-only"> ({{ __('admin::forms.required') }})</span>@endif</label>
    @endif
    <textarea
        id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($max) maxlength="{{ $max }}" @endif
        @if ($invalid) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @required($required)
        x-on:input="count = [...$event.target.value].length"
        {{ $attributes->class([
            'w-full resize-none rounded-control border bg-surface px-3.5 py-2.5 text-body leading-normal text-ink outline-none placeholder:text-ink-3',
            'border-field focus:border-accent focus:ring-4 focus:ring-ring' => ! $invalid,
            'border-over-text ring-1 ring-over-text' => $invalid,
        ]) }}
    >{{ $value }}</textarea>
    <div class="flex items-start justify-between gap-4 text-caption">
        <div class="flex flex-col gap-1">
            @if ($error)<p id="{{ $id }}-error" class="flex items-center gap-1.5 font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ $error }}</p>@endif
            @if ($hint)<p id="{{ $id }}-hint" class="text-ink-3">{{ $hint }}</p>@endif
        </div>
        @if ($max)
            <p id="{{ $id }}-count" aria-live="polite" class="shrink-0 text-ink-3" x-bind:class="count > {{ $max }} ? 'text-over-text font-bold' : ''">
                <bdi dir="ltr"><span x-text="count">{{ mb_strlen((string) $value) }}</span> / {{ $max }}</bdi>
            </p>
        @endif
    </div>
</div>
