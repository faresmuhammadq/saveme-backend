<x-billing::mail-layout :subject="__('billing.claim_expired.subject')">
    <p>{{ __('billing.claim_expired.intro', ['plan' => $plan, 'tail' => $tail]) }}</p>
    <p>{{ __('billing.claim_expired.support', ['contact' => $contact, 'claim' => $claimId]) }}</p>
</x-billing::mail-layout>
