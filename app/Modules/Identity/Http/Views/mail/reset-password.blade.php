<x-identity::mail-layout :mail-key="$mailKey">
    <p>{{ __('identity::mail.reset_password.intro') }}</p>
    <p><a href="{{ $link }}" dir="ltr">{{ __('identity::mail.reset_password.action') }}</a></p>
    <p>{{ __('identity::mail.reset_password.expiry') }}</p>
    <p>{{ __('identity::mail.reset_password.ignore') }}</p>
</x-identity::mail-layout>
