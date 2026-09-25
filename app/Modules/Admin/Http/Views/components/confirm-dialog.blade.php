{{--
    Confirmation dialog (spec 006, FR-014). role="alertdialog"; the initial
    focus is Cancel, so Enter never confirms by accident. With
    `confirm-phrase`, the operator must type it before Confirm enables.
    confirm: the wire method to call.
--}}
@props(['name', 'title', 'description' => null, 'tone' => 'danger', 'confirmLabel' => null, 'cancelLabel' => null, 'confirmPhrase' => null, 'confirm' => null, 'open' => false])

<x-admin::dialog
    :name="$name" :title="$title" :description="$description" size="sm" role="alertdialog" :open="$open"
    :icon="$tone === 'danger' ? 'warning' : 'info'" :tone="$tone === 'danger' ? 'danger' : 'default'"
    state="{ typed: '' }"
    {{ $attributes }}
>
    <div class="flex flex-col gap-2">
        {{ $slot }}
        @if ($confirmPhrase)
            <p class="rounded-control bg-sunken px-3 py-2.5 text-label text-ink-2">
                {!! __('admin::feedback.type_to_confirm', ['phrase' => '<strong dir="ltr" class="font-mono text-ink">'.e($confirmPhrase).'</strong>']) !!}
            </p>
            <input type="text" dir="ltr" x-model="typed" aria-label="{{ __('admin::feedback.confirmation_phrase') }}" autocomplete="off" class="h-10 rounded-control border border-field px-3 font-mono text-body outline-none focus:border-accent focus:ring-4 focus:ring-ring">
        @endif
        <div class="mt-3 flex justify-end gap-2.5">
            <x-admin::button variant="secondary" :autofocus="! $open" x-on:click="$dispatch('admin-dialog-close', { name: {{ \Illuminate\Support\Js::from($name) }} })">{{ $cancelLabel ?? __('admin::feedback.cancel') }}</x-admin::button>
            <x-admin::button
                :variant="$tone === 'danger' ? 'danger' : 'primary'"
                :disabled="(bool) $confirmPhrase"
                data-confirm
                :x-bind:disabled="$confirmPhrase ? 'typed !== '.\Illuminate\Support\Js::from($confirmPhrase) : null"
                :wire:click="$confirm"
            >{{ $confirmLabel ?? __('admin::feedback.confirm') }}</x-admin::button>
        </div>
    </div>
</x-admin::dialog>
