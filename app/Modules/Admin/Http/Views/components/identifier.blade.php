{{--
    Identifier (spec 006, FR-024): monospace, left-to-right, middle-truncated;
    the copy button copies the full value and confirms for two seconds.
--}}
@props(['value', 'label' => null, 'copy' => true])

@php
    $shown = app(\App\Modules\Admin\Http\Presenters\IdentifierPresenter::class)->truncate($value);
    $label ??= $value;
@endphp

<span x-data="adminCopy(@js($value))" {{ $attributes->class(['inline-flex items-center gap-1.5']) }}>
    <bdi dir="ltr" title="{{ $value }}" class="rounded-lg bg-sunken px-2 py-1 font-mono text-mono text-ink">{{ $shown }}</bdi>
    @if ($copy)
        <x-admin::icon-button icon="copy" :label="__('admin::values.copy', ['label' => $label])" size="sm" x-on:click="copy()" />
        <span x-show="copied" x-cloak role="status" class="inline-flex items-center gap-1 text-caption font-bold text-positive-text"><x-admin::icon name="check" :size="14" />{{ __('admin::values.copied') }}</span>
    @endif
</span>
