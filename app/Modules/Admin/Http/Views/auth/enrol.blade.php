{{-- Set up the authenticator: setup key and link, then the first code (spec 018, FR-021, R-4). --}}
<x-admin::layouts.auth :title="__('admin::auth.enrol.heading')" :step="__('admin::auth.steps.enrol')">
    <h1 class="text-h2 font-extrabold text-ink">{{ __('admin::auth.enrol.heading') }}</h1>

    <ol class="flex flex-col gap-4 text-body text-ink-2">
        <li>{{ __('admin::auth.enrol.step_open') }}</li>
        <li class="flex flex-col gap-3">
            <span>{{ __('admin::auth.enrol.step_add') }}</span>
            <x-admin::setup-key :key="$setup->keyGrouped" :link="$setup->link" :label="$setup->label" />
        </li>
        <li>{{ __('admin::auth.enrol.step_code') }}</li>
    </ol>

    <form method="POST" action="{{ route('admin.enrol.submit') }}" class="flex flex-col gap-4">
        @csrf
        <x-admin::code-input :error="session('admin.error')" data-test="enrol-code" />
        <x-admin::button type="submit" size="lg" class="w-full">{{ __('admin::auth.enrol.link_authenticator') }}</x-admin::button>
    </form>
</x-admin::layouts.auth>
