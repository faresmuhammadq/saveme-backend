{{--
    Layout for full-page back-office Livewire components (spec 006): the
    component renders inside the shell. Pass shell props with
    ->layout('admin::page', ['title' => …, 'breadcrumbs' => …]).
--}}
<x-admin::layouts.shell
    :title="$title ?? config('app.name')"
    :subtitle="$subtitle ?? null"
    :breadcrumbs="$breadcrumbs ?? []"
    :operator="$operator ?? null"
    :sign-out-url="$signOutUrl ?? null"
    :navigation="$navigation ?? null"
>
    @auth('operator')
        <x-slot:notifications><livewire:admin::operator-notification-bell /></x-slot:notifications>
    @endauth
    {{ $slot }}
</x-admin::layouts.shell>
