<x-billing::mail-layout :subject="__('billing.claim_rejected.subject')">
    <p>{{ __('billing.claim_rejected.intro', ['plan' => $plan]) }}</p>
    <p>{{ __('billing.claim_rejected.reason', ['reason' => __("billing.reject_reasons.{$reason}")]) }}</p>
    <p>{{ __('billing.claim_rejected.support', ['contact' => $contact, 'claim' => $claimId]) }}</p>
</x-billing::mail-layout>
