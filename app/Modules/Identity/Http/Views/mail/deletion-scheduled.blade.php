<x-identity::mail-layout :mail-key="$mailKey">
    <p>{!! __('identity::mail.deletion_scheduled.intro', ['date' => '<bdi dir="ltr">'.e($date).'</bdi>']) !!}</p>
    <p>{{ __('identity::mail.deletion_scheduled.keep') }}</p>
    <p>{{ __('identity::mail.deletion_scheduled.export') }}</p>
    <p>{{ __('identity::mail.deletion_scheduled.google_play') }}</p>
</x-identity::mail-layout>
