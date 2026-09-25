{{--
    Dialog (spec 006, FR-014, FR-019). A native <dialog> shown modally, so the
    page behind is inert and Escape closes it; focus is trapped and returned
    to the opener (adminDialog in Assets/admin.js).

    Open:  $dispatch('admin-dialog-open', { name: 'reject-claim' })
           or from Livewire $this->dispatch('admin-dialog-open', name: 'reject-claim')
    Close: the same with admin-dialog-close.
    `open` renders it open in place, non-modal (the gallery).
    role: dialog | alertdialog · size: sm | md | lg · placement: center | end
    state: extra Alpine state shared by body and footer (a JS object literal).
    Slots: default (body), footer.
--}}
@props([
    'name',
    'title',
    'description' => null,
    'size' => 'md',
    'dismissible' => true,
    'open' => false,
    'role' => 'dialog',
    'placement' => 'center',
    'icon' => null,
    'tone' => 'default',
    'state' => '{}',
])

@php
    $titleId = "{$name}-title";
    $descriptionId = $description ? "{$name}-description" : null;
@endphp

<dialog
    id="{{ $name }}"
    @if ($role === 'alertdialog') role="alertdialog" @endif
    aria-labelledby="{{ $titleId }}"
    @if ($descriptionId) aria-describedby="{{ $descriptionId }}" @endif
    @if ($open) open @endif
    x-data="adminDialog(@js($name), {{ $dismissible ? 'true' : 'false' }}, {{ $state }})"
    x-on:admin-dialog-open.window="if ($event.detail?.name === name) show()"
    x-on:admin-dialog-close.window="if ($event.detail?.name === name) hide()"
    x-on:cancel="onCancel($event)"
    x-on:close="restoreFocus()"
    x-trap.noscroll="trapped"
    {{ $attributes->class([
        'overflow-hidden bg-surface p-0 text-ink shadow-overlay backdrop:bg-scrim',
        'relative m-auto rounded-hero' => $placement === 'center',
        'w-105 max-w-full' => $placement === 'center' && $size === 'sm',
        'w-130 max-w-full' => $placement === 'center' && $size === 'md',
        'w-180 max-w-full' => $placement === 'center' && $size === 'lg',
        'ms-auto me-0 my-0 flex w-90 max-w-full flex-col' => $placement === 'end',
        'h-dvh max-h-dvh' => $placement === 'end' && ! $open,
        'h-full' => $placement === 'end' && $open,
        'static' => $open,
    ]) }}
>
    <div @class(['flex gap-3.5 px-6 pt-5.5', 'items-center border-b border-line pb-4.5' => $placement === 'end'])>
        @if ($icon)
            <div @class([
                'flex size-10 shrink-0 items-center justify-center rounded-control',
                'bg-over-soft text-over-text' => $tone === 'danger',
                'bg-positive-soft text-positive-text' => $tone === 'positive',
                'bg-accent-soft text-accent' => $tone === 'default',
            ])><x-admin::icon :name="$icon" :size="20" /></div>
        @endif
        <div class="min-w-0 flex-1">
            <h2 id="{{ $titleId }}" class="text-lg font-extrabold text-ink">{{ $title }}</h2>
            @if ($description)<p id="{{ $descriptionId }}" class="mt-1 text-label leading-normal text-ink-2">{{ $description }}</p>@endif
        </div>
        @if ($dismissible)
            <x-admin::icon-button icon="close" :label="__('admin::feedback.close')" size="sm" x-on:click="hide()" />
        @endif
    </div>
    <div @class(['px-6 py-4.5', 'flex-1 overflow-y-auto' => $placement === 'end'])>{{ $slot }}</div>
    @if (isset($footer) && $footer->isNotEmpty())
        <div class="flex justify-end gap-2.5 border-t border-line bg-sunken px-6 py-3.5">{{ $footer }}</div>
    @endif
</dialog>
