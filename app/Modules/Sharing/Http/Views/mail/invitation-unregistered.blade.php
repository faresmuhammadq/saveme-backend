<x-sharing::mail-layout :mail-key="$mailKey">
    <p>{{ $inviter === null
        ? __('sharing::mail.invitation_unregistered.intro_neutral', ['app' => config('app.name'), 'role' => __("sharing::mail.roles.{$role}")])
        : __('sharing::mail.invitation_unregistered.intro_named', ['app' => config('app.name'), 'inviter' => $inviter, 'role' => __("sharing::mail.roles.{$role}")]) }}</p>
    <p>{{ __('sharing::mail.invitation_unregistered.create_account', ['app' => config('app.name')]) }}</p>
    <p><a href="{{ $link }}" dir="ltr">{{ __('sharing::mail.invitation_unregistered.action') }}</a></p>
    <p>{{ __('sharing::mail.invitation_unregistered.expiry') }}</p>
    <p>{{ __('sharing::mail.invitation_unregistered.ignore') }}</p>
</x-sharing::mail-layout>
