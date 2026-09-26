<x-identity::mail-layout :mail-key="$mailKey">
    <p>{{ __('identity::mail.verify_address.intro') }}</p>
    <p><a href="{{ $link }}" dir="ltr">{{ __('identity::mail.verify_address.action') }}</a></p>
    <p>{{ __('identity::mail.verify_address.expiry') }}</p>
    <p>{{ __('identity::mail.verify_address.ignore') }}</p>
</x-identity::mail-layout>
