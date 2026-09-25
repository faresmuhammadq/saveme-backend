{{--
    Field wrapper (spec 006, FR-012): label, required marker, hint and error.
    The control inside points aria-describedby at "{for}-hint {for}-error";
    controls built on <x-admin::field> do this themselves.
--}}
@props(['for', 'label' => null, 'required' => false, 'hint' => null, 'error' => null])

<div {{ $attributes->class(['flex flex-col gap-1.5']) }}>
    @if ($label)
        <label for="{{ $for }}" class="text-label font-semibold text-ink">
            {{ $label }}@if ($required)<span class="ms-0.5 text-over-text" aria-hidden="true">*</span><span class="sr-only"> ({{ __('admin::forms.required') }})</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($error)
        <p id="{{ $for }}-error" class="flex items-center gap-1.5 text-caption font-semibold text-over-text">
            <x-admin::icon name="error" :size="14" />{{ $error }}
        </p>
    @endif
    @if ($hint)
        <p id="{{ $for }}-hint" class="text-caption text-ink-3">{{ $hint }}</p>
    @endif
</div>
