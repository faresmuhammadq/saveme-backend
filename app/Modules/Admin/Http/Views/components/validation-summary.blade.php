{{--
    Validation summary (spec 006, FR-012): lists the fields in error, each a
    link that moves focus to the field. Renders nothing when there are none.
    errors: array<field id, message>
--}}
@props(['errors' => []])

@if (count($errors) > 0)
    <div role="alert" {{ $attributes->class(['flex gap-3 rounded-tile bg-over-soft px-4 py-3.5 text-over-text']) }}>
        <x-admin::icon name="error" class="mt-0.5" />
        <div class="text-label">
            <p class="font-bold">{{ trans_choice('admin::forms.validation_summary', count($errors), ['count' => count($errors)]) }}</p>
            <ul class="mt-1 flex flex-col gap-0.5">
                @foreach ($errors as $field => $message)
                    <li><a href="#{{ $field }}" class="underline underline-offset-4" x-data x-on:click="document.getElementById(@js($field))?.focus()">{{ $message }}</a></li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
