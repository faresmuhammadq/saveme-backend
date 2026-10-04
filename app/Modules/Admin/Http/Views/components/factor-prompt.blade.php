{{--
    Factor prompt (spec 018, FR-060; contracts/second-factor.md, Re-confirmation
    trait). Rendered by a screen that uses RequiresRecentFactor; it follows
    $factorOpen and submits through confirmFactor(). The server check is
    authoritative. One field takes a one-time code or a recovery code.
--}}
@props(['name' => 'factor-prompt'])

<x-admin::dialog
    :name="$name"
    :title="__('admin::auth.factor.title')"
    :description="__('admin::auth.factor.prompt')"
    icon="lock"
    tone="positive"
    size="sm"
    state="{ code: '', recovery: false }"
    x-effect="typeof $wire !== 'undefined' && ($wire.factorOpen ? show() : hide())"
    {{ $attributes }}
>
    <form class="flex flex-col gap-2" x-on:submit.prevent="$wire.confirmFactor(code)">
        <x-admin::field for="{{ $name }}-code" :error="$errors->first('one_time_code')">
            <input
                id="{{ $name }}-code" type="text" dir="ltr" autocomplete="one-time-code" required
                x-model="code"
                x-bind:inputmode="recovery ? 'text' : 'numeric'"
                x-bind:aria-label="recovery ? @js(__('admin::auth.recovery.label')) : @js(__('admin::auth.second_factor.code_label'))"
                data-test="factor-code"
                @if ($errors->has('one_time_code')) aria-invalid="true" @endif
                class="h-10 w-full rounded-control border border-field bg-surface px-3.5 text-center font-mono text-body text-ink outline-none focus:border-accent focus:ring-4 focus:ring-ring"
            >
        </x-admin::field>
        <button type="button" class="self-start text-label font-semibold text-accent hover:underline focus-ring-filled" x-on:click="recovery = ! recovery; code = ''">
            <span x-show="! recovery">{{ __('admin::auth.factor.use_recovery') }}</span>
            <span x-show="recovery" x-cloak>{{ __('admin::auth.factor.use_code') }}</span>
        </button>
    </form>
    <x-slot:footer>
        <x-admin::button variant="ghost" x-on:click="typeof $wire !== 'undefined' ? $wire.cancelFactor() : hide()">{{ __('admin::feedback.cancel') }}</x-admin::button>
        <x-admin::button icon="check" data-test="factor-confirm" x-bind:disabled="code.trim() === ''" x-on:click="$wire.confirmFactor(code)">{{ __('admin::auth.factor.confirm') }}</x-admin::button>
    </x-slot:footer>
</x-admin::dialog>
