<x-identity::mail-layout :mail-key="$mailKey">
    <p>{!! __('identity::mail.deletion_cancelled_by_sign_in.intro', ['device' => '<bdi>'.e($device).'</bdi>', 'time' => '<bdi dir="ltr">'.e($time).'</bdi>']) !!}</p>
    <p>{{ __('identity::mail.deletion_cancelled_by_sign_in.not_you') }}</p>
</x-identity::mail-layout>
