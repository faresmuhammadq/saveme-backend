<x-billing::mail-layout :subject="__('billing.claim_approved.subject', ['app' => config('app.name')])">
    <p>{{ __('billing.claim_approved.intro', ['plan' => $plan]) }}</p>
    <p>{{ __('billing.claim_approved.code_label') }}:</p>
    <p dir="ltr" style="font-family: ui-monospace, monospace; font-size: 22px; letter-spacing: 2px;"><strong>{{ $code }}</strong></p>
    <p>{{ __('billing.claim_approved.enter') }}</p>
    <p>{{ __('billing.claim_approved.account') }}</p>
    <p>{{ __('billing.claim_approved.expiry', ['date' => $expiresOn]) }}</p>
    <p>{{ __('billing.claim_approved.support', ['contact' => $contact]) }}</p>
</x-billing::mail-layout>
