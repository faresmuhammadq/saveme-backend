{{--
    Reason prompt (spec 006, FR-017, FR-018; contracts/reason-prompt.md).
    Every audited action opens it first. Rendered by a Livewire screen that
    uses the RequiresReason trait; it follows $reasonOpen and submits through
    confirmReason(). The server check is authoritative; the disabled Confirm
    button is a convenience.

    action-key: selects admin::reason.actions.{key}.title / .consequence
    presets: reason translation keys under admin::reason.presets
    attribution: who is acting, e.g. "Mariam S."
--}}
@props([
    'actionKey',
    'tone' => 'default',
    'presets' => [],
    'attribution' => null,
    'confirmLabel' => null,
    'error' => null,
    'name' => 'reason-prompt',
    'open' => false,
])

@php
    $max = \App\Modules\Admin\Domain\AuditReason::MAX_LENGTH;
    $actionCode = 'admin_action.'.$actionKey;
@endphp

<x-admin::dialog
    :name="$name"
    :title="__('admin::reason.actions.'.$actionKey.'.title')"
    :description="__('admin::reason.actions.'.$actionKey.'.consequence')"
    :icon="$tone === 'danger' ? 'error' : 'audit'"
    :tone="$tone === 'danger' ? 'danger' : 'positive'"
    :open="$open"
    state="{ reason: '' }"
    :x-effect="$open ? null : 'typeof $wire !== \'undefined\' && ($wire.reasonOpen ? show() : hide())'"
    {{ $attributes }}
>
    <div class="flex flex-col gap-2.5">
        <label for="{{ $name }}-reason" class="text-label font-bold text-ink">{{ __('admin::reason.question') }} <span class="text-over-text" aria-hidden="true">*</span><span class="sr-only">({{ __('admin::forms.required') }})</span></label>
        @if ($presets)
            <div class="flex flex-wrap gap-2">
                @foreach ($presets as $preset)
                    <button type="button" class="h-7.5 rounded-full border border-line bg-surface px-3 text-caption font-semibold text-ink-2 hover:border-accent hover:bg-accent-soft hover:text-accent focus-ring-filled" x-on:click="reason = @js(__('admin::reason.presets.'.$preset)); $nextTick(() => $refs.reason.focus())">{{ __('admin::reason.presets.'.$preset) }}</button>
                @endforeach
            </div>
        @endif
        <textarea
            id="{{ $name }}-reason"
            x-ref="reason"
            x-model="reason"
            rows="3"
            maxlength="{{ $max }}"
            required
            @unless ($open) autofocus @endunless
            aria-describedby="{{ $name }}-count{{ $error ? ' '.$name.'-error' : '' }}"
            @if ($error) aria-invalid="true" @endif
            @class([
                'w-full resize-none rounded-control border bg-surface px-3.5 py-2.5 text-body leading-normal outline-none',
                'border-field focus:border-accent focus:ring-4 focus:ring-ring' => ! $error,
                'border-over-text ring-1 ring-over-text' => $error,
            ])
        ></textarea>
        <div class="flex items-start justify-between gap-4 text-caption text-ink-3">
            @if ($error)
                <p id="{{ $name }}-error" class="flex items-center gap-1.5 font-semibold text-over-text"><x-admin::icon name="error" :size="14" />{{ $error }}</p>
            @elseif ($attribution)
                <p class="flex items-center gap-1.5"><x-admin::icon name="audit" :size="14" />{{ __('admin::reason.attribution', ['who' => $attribution]) }} · <bdi dir="ltr" class="font-mono">{{ $actionCode }}</bdi></p>
            @else
                <span></span>
            @endif
            <p id="{{ $name }}-count" aria-live="polite" class="shrink-0"><bdi dir="ltr"><span x-text="[...reason].length">0</span> / {{ $max }}</bdi></p>
        </div>
    </div>
    <x-slot:footer>
        <x-admin::button variant="ghost" x-on:click="typeof $wire !== 'undefined' ? $wire.cancelReason() : hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
        <x-admin::button
            :variant="$tone === 'danger' ? 'danger' : 'primary'"
            icon="check"
            :disabled="true"
            data-confirm
            x-bind:disabled="reason.trim() === ''"
            x-on:click="$wire.confirmReason(reason)"
            wire:loading.attr="aria-busy"
        >{{ $confirmLabel ?? __('admin::reason.confirm') }}</x-admin::button>
    </x-slot:footer>
</x-admin::dialog>
