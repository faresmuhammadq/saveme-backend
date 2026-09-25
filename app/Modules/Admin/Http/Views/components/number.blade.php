{{-- Number input (spec 006, FR-012): Western digits, left-to-right, end-aligned. --}}
@props(['name', 'id' => null, 'label' => null, 'hint' => null, 'error' => null, 'required' => false, 'invalid' => false])

<x-admin::input
    :name="$name" :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required" :invalid="$invalid"
    type="text" dir="ltr" inputmode="numeric"
    {{ $attributes->class(['text-end font-bold']) }}
/>
