<x-identity::mail-layout :mail-key="$mailKey">
    <p>{!! __('identity::mail.deletion_reminder.intro', ['date' => '<bdi dir="ltr">'.e($date).'</bdi>']) !!}</p>
    <p>{{ __('identity::mail.deletion_reminder.keep') }}</p>
    <p>{{ __('identity::mail.deletion_reminder.export') }}</p>
    <p>{{ __('identity::mail.deletion_reminder.google_play') }}</p>
</x-identity::mail-layout>
