{{--
    Six digit boxes over one ordinary field (spec 018, FR-092). Without
    JavaScript the field is all there is and it submits as `one_time_code`;
    with it, the boxes take a pasted code, move focus with the arrow keys and
    Backspace, and each has an accessible name. Errors are announced through
    an aria-live region. The digits always read left to right.
--}}
@props(['name' => 'one_time_code', 'error' => null, 'label' => null])

<div x-data="adminCodeInput(6)" class="flex flex-col gap-2">
    <label for="{{ $name }}" class="text-label font-semibold text-ink">{{ $label ?? __('admin::auth.second_factor.code_label') }}</label>

    <div x-ref="boxes" x-cloak x-show="enhanced" dir="ltr" class="flex items-center gap-2.5">
        @for ($digit = 1; $digit <= 6; $digit++)
            @if ($digit === 4)<span class="h-0.5 w-2.5 shrink-0 rounded-full bg-field" aria-hidden="true"></span>@endif
            <input
                type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                data-box
                aria-label="{{ __('admin::auth.second_factor.digit_label', ['number' => $digit]) }}"
                @if ($error) aria-invalid="true" @endif
                x-on:input="onInput({{ $digit - 1 }}, $event)"
                x-on:keydown="onKeydown({{ $digit - 1 }}, $event)"
                x-on:paste="onPaste({{ $digit - 1 }}, $event)"
                class="h-16 w-14 min-w-0 rounded-control border border-field bg-surface text-center text-numeral-l font-extrabold text-ink outline-none focus:border-accent focus:ring-4 focus:ring-ring"
            >
        @endfor
    </div>

    <input
        x-ref="field"
        x-bind:type="enhanced ? 'hidden' : 'text'"
        type="text" id="{{ $name }}" name="{{ $name }}"
        inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="7" autofocus
        data-test="code-input"
        @if ($error) aria-invalid="true" @endif
        aria-describedby="{{ $name }}-error"
        {{ $attributes->class('h-10 w-full rounded-control border border-field bg-surface px-3.5 text-center font-mono text-body text-ink outline-none focus:border-accent focus:ring-4 focus:ring-ring') }}
    >

    <p id="{{ $name }}-error" role="alert" aria-live="assertive" data-test="auth-error" @class(['flex items-center gap-1.5 text-caption font-semibold text-over-text', 'hidden' => ! $error])>
        @if ($error)<x-admin::icon name="error" :size="14" />{{ $error }}@endif
    </p>
</div>
