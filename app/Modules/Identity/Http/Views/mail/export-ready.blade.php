<x-identity::mail-layout :mail-key="$mailKey">
    <p>{{ __('identity::mail.export_ready.intro') }}</p>
    <p><a href="{{ $link }}" dir="ltr">{{ __('identity::mail.export_ready.action') }}</a></p>
    <p>{!! __('identity::mail.export_ready.details', ['size' => '<bdi dir="ltr">'.e($size).'</bdi>', 'expiry' => '<bdi dir="ltr">'.e($expiry).'</bdi>']) !!}</p>
    <p>{{ __('identity::mail.export_ready.ignore') }}</p>
</x-identity::mail-layout>
