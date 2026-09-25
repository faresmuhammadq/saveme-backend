{{--
    Toast stack (spec 006, FR-014): one per layout, bottom-end corner.
    Raise a toast from Livewire with
        $this->dispatch('admin-toast', tone: 'success', message: '…', undo: 'price-restore');
    `undo` names an event the screen listens for with #[On('price-restore')].
    Errors stay until dismissed; others leave after 6 s, paused on hover or focus.
    `toasts` renders toasts server-side (flash messages, the gallery).
--}}
@props(['toasts' => []])

@php
    $toneStyles = [
        'success' => ['icon' => 'check', 'box' => 'bg-positive-soft text-positive-text'],
        'info' => ['icon' => 'info', 'box' => 'bg-accent-soft text-accent'],
        'error' => ['icon' => 'error', 'box' => 'bg-over-soft text-over-text'],
    ];
@endphp

<div
    x-data="adminToasts(@js($toasts), @js($toneStyles))"
    x-on:admin-toast.window="push($event.detail)"
    {{ $attributes->class(['pointer-events-none fixed end-6 bottom-6 z-50 flex w-95 flex-col items-stretch gap-3']) }}
>
    <div aria-live="polite" aria-atomic="false" class="contents">
        <template x-for="toast in items" :key="toast.id">
            <div
                x-bind:role="toast.tone === 'error' ? 'alert' : 'status'"
                x-on:mouseenter="pause(toast)" x-on:mouseleave="resume(toast)" x-on:focusin="pause(toast)" x-on:focusout="resume(toast)"
                class="pointer-events-auto flex items-center gap-3 rounded-tile bg-surface py-3.5 ps-4 pe-3.5 shadow-overlay"
            >
                <span class="flex size-7.5 shrink-0 items-center justify-center rounded-lg" x-bind:class="styles[toast.tone]?.box">
                    <template x-if="toast.tone === 'error'"><x-admin::icon name="error" :size="16" /></template>
                    <template x-if="toast.tone === 'success'"><x-admin::icon name="check" :size="16" /></template>
                    <template x-if="toast.tone !== 'error' && toast.tone !== 'success'"><x-admin::icon name="info" :size="16" /></template>
                </span>
                <span class="flex-1 text-body font-semibold text-ink" x-text="toast.message"></span>
                <template x-if="toast.undo">
                    <button type="button" class="h-7.5 rounded-lg px-2.5 text-body font-bold text-accent hover:bg-accent-soft focus-ring-filled" x-on:click="undo(toast)">{{ __('admin::feedback.undo') }}</button>
                </template>
                <x-admin::icon-button icon="close" :label="__('admin::feedback.dismiss')" size="sm" x-on:click="dismiss(toast)" />
            </div>
        </template>
    </div>
    <noscript>
        @foreach ($toasts as $toast)
            <div role="{{ ($toast['tone'] ?? 'info') === 'error' ? 'alert' : 'status' }}" class="pointer-events-auto rounded-tile bg-surface p-4 shadow-overlay">{{ $toast['message'] }}</div>
        @endforeach
    </noscript>
</div>
