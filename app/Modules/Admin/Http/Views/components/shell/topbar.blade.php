{{-- Top bar (spec 006, FR-006, FR-009): breadcrumbs, search and notifications slots, environment badge. --}}
@props(['breadcrumbs' => []])

@php
    $environment = app()->environment();
    $known = in_array($environment, ['local', 'staging', 'production'], true);
@endphp

<header class="flex h-15 shrink-0 items-center gap-3.5 border-b border-line bg-surface px-8">
    @if ($breadcrumbs)<x-admin::breadcrumbs :items="$breadcrumbs" />@endif
    <div class="flex-1"></div>
    @if (isset($search) && $search->isNotEmpty()){{ $search }}@endif
    <x-admin::pill :tone="$environment === 'production' ? 'over' : 'neutral'" size="sm" data-environment="{{ $environment }}">
        {{ $known ? __('admin::shell.environment.'.$environment) : $environment }}
    </x-admin::pill>
    @if (isset($notifications) && $notifications->isNotEmpty()){{ $notifications }}@endif
</header>
