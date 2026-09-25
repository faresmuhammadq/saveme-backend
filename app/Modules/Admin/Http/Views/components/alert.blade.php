{{--
    Alert (spec 006, FR-014). tone: info | success | warning | error.
    Info and success are polite status messages; warning and error are alerts.
    Slots: default (body), action.
--}}
@props(['tone' => 'info', 'title' => null, 'dismissible' => false])

@php
    $icon = ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'error' => 'error'][$tone] ?? 'info';
@endphp

<div
    role="{{ in_array($tone, ['warning', 'error'], true) ? 'alert' : 'status' }}"
    @if ($dismissible) x-data="{ shown: true }" x-show="shown" @endif
    {{ $attributes->class([
        'flex items-start gap-3 rounded-tile px-4 py-3.5',
        'bg-accent-soft text-accent' => $tone === 'info',
        'bg-positive-soft text-positive-text' => $tone === 'success',
        'bg-pending-soft text-pending-text' => $tone === 'warning',
        'bg-over-soft text-over-text' => $tone === 'error',
    ]) }}
>
    <x-admin::icon :name="$icon" class="mt-0.5" />
    <div class="flex-1 text-body leading-normal text-ink">
        @if ($title)<strong class="font-bold">{{ $title }}</strong>@endif
        <span class="text-ink-2">{{ $slot }}</span>
    </div>
    @isset($action)<div class="shrink-0">{{ $action }}</div>@endisset
    @if ($dismissible)<x-admin::icon-button icon="close" :label="__('admin::feedback.dismiss')" size="sm" x-on:click="shown = false" />@endif
</div>
