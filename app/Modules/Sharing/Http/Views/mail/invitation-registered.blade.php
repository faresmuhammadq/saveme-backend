<x-sharing::mail-layout :mail-key="$mailKey">
    <p>{{ $inviter === null
        ? __('sharing::mail.invitation_registered.intro_neutral', ['app' => config('app.name'), 'role' => __("sharing::mail.roles.{$role}")])
        : __('sharing::mail.invitation_registered.intro_named', ['app' => config('app.name'), 'inviter' => $inviter, 'role' => __("sharing::mail.roles.{$role}")]) }}</p>
    <p><a href="{{ $link }}" dir="ltr">{{ __('sharing::mail.invitation_registered.action') }}</a></p>
    <p>{{ __('sharing::mail.invitation_registered.expiry') }}</p>
    <p>{{ __('sharing::mail.invitation_registered.ignore') }}</p>
</x-sharing::mail-layout>
