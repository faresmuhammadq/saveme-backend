{{--
    The back-office shell (spec 006, FR-006 to FR-010, FR-051;
    contracts/shell-and-navigation.md). Every back-office page renders in it.

    operator / sign-out-url: bound by operator sign-in (brief D2); null until then.
    navigation: null reads the NavigationRegistry; the gallery passes samples.
    Slots: default, actions, status, header, search, notifications.
--}}
@props([
    'title',
    'subtitle' => null,
    'breadcrumbs' => [],
    'operator' => null,
    'signOutUrl' => null,
    'navigation' => null,
])

@php
    $navigation ??= app(\App\Modules\Admin\Http\Navigation\NavigationRegistry::class)->groups(request()->route()?->getName());
@endphp

<x-admin::layouts.document :title="$title">
    <a href="#content" class="sr-only rounded-control bg-accent px-4 py-2 font-bold text-on-accent focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50">{{ __('admin::shell.skip_to_content') }}</a>
    <div class="flex min-h-dvh">
        <x-admin::shell.sidebar :navigation="$navigation" :operator="$operator" :sign-out-url="$signOutUrl" />
        <div class="flex min-w-0 flex-1 flex-col">
            <x-admin::shell.topbar :breadcrumbs="$breadcrumbs">
                <x-slot:search>{{ $search ?? '' }}</x-slot:search>
                <x-slot:notifications>{{ $notifications ?? '' }}</x-slot:notifications>
            </x-admin::shell.topbar>
            <main id="content" tabindex="-1" class="flex w-full max-w-300 flex-1 flex-col gap-5 px-8 py-7 outline-none">
                @isset($header)
                    {{ $header }}
                @else
                    <x-admin::shell.page-header :title="$title" :subtitle="$subtitle">
                        <x-slot:status>{{ $status ?? '' }}</x-slot:status>
                        <x-slot:actions>{{ $actions ?? '' }}</x-slot:actions>
                    </x-admin::shell.page-header>
                @endisset
                {{ $slot }}
            </main>
        </div>
    </div>
    <x-admin::toast-stack />
</x-admin::layouts.document>
