{{--
    Money input (spec 006, FR-012, P-03). Shows major units for editing and
    submits integer minor units under `name`; conversion is string-based
    (adminMoneyInput in Assets/admin.js), never floating point.
--}}
@props(['name', 'currency', 'value' => null, 'id' => null, 'label' => null, 'hint' => null, 'error' => null, 'required' => false])

@php
    $id ??= $name;
    $money = app(\App\Modules\Admin\Http\Presenters\MoneyPresenter::class);
    $minor = $value === null ? null : (int) $value;
    $hint ??= $minor === null ? null : __('admin::values.stored_as', ['minor' => $minor]);
@endphp

<div x-data="adminMoneyInput({ minor: @js($minor === null ? '' : (string) $minor), exponent: 2 })" {{ $attributes->only('class') }}>
    <x-admin::input
        :name="$name.'_display'" :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required"
        type="text" dir="ltr" inputmode="decimal"
        :value="$minor === null ? '' : $money->plain($minor, $currency)"
        x-model="display"
        x-on:input="sync()"
        class="text-end text-lead font-bold"
    >
        <x-slot:prefix>{{ strtoupper($currency) }}</x-slot:prefix>
    </x-admin::input>
    <input type="hidden" name="{{ $name }}" x-bind:value="minor" value="{{ $minor }}" {{ $attributes->whereStartsWith('wire:model') }}>
</div>
