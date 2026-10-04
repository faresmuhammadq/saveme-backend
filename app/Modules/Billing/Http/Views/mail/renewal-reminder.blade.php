<x-billing::mail-layout :subject="__('billing.renewal_reminder.subject', ['app' => config('app.name')])">
    <p>{{ __('billing.renewal_reminder.intro', ['plan' => $plan, 'date' => $endsOn]) }}</p>
    <p><a href="{{ $renewUrl }}" dir="ltr">{{ __('billing.renewal_reminder.action') }}</a></p>
    <p>{{ __('billing.renewal_reminder.note') }}</p>
</x-billing::mail-layout>
