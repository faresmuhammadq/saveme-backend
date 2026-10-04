<?php

/*
 * Billing text (spec 020, P-14): the claim and renewal mails, the reasons a
 * claim can be rejected for, and the messages of the operator review
 * commands. Keys mirror lang/ar/billing.php exactly. Numerals stay Western.
 */
return [

    'greeting' => 'Hello,',
    'signature' => '— The :app team',

    'refusals' => [
        'claim_not_reviewable' => 'This claim cannot be reviewed: it is not waiting for review, or its 7 days have passed.',
        'reference_already_approved' => 'Another claim with the same reference is already approved.',
        'code_not_revocable' => 'Only a code that was issued and not yet used can be revoked.',
    ],

    'reject_reasons' => [
        'reference_not_found' => 'We could not find a payment with the reference you gave.',
        'amount_mismatch' => 'The amount we found does not match the price of the plan.',
        'duplicate' => 'This payment has already been claimed.',
        'other' => 'We could not confirm the payment.',
    ],

    'claim_approved' => [
        'subject' => 'Your :app code is ready',
        'intro' => 'We confirmed your InstaPay payment for :plan.',
        'code_label' => 'Your code',
        'enter' => 'Open the app, go to Plan, tap Enter code and type it in.',
        'account' => 'Sign in, or create an account, with this email address, then enter the code. It only works for this address.',
        'expiry' => 'The code works once and is valid until :date.',
        'support' => 'Need help? Contact :contact.',
    ],

    'claim_rejected' => [
        'subject' => 'We could not confirm your payment',
        'intro' => 'We reviewed your InstaPay claim for :plan, but we could not approve it.',
        'reason' => 'Reason: :reason',
        'support' => 'If you think this is a mistake, contact :contact and quote your claim number :claim.',
    ],

    'claim_expired' => [
        'subject' => 'Your payment claim expired',
        'intro' => 'Your InstaPay claim for :plan (reference ending :tail) was not reviewed within 7 days, so it has closed.',
        'support' => 'If you did pay, contact :contact and quote your claim number :claim.',
    ],

    'renewal_reminder' => [
        'subject' => 'Your :app plan ends soon',
        'intro' => 'Your :plan plan ends on :date.',
        'action' => 'Renew your plan',
        'note' => 'To keep your plan without a break, renew before it ends. Your new period will start where this one ends.',
    ],

];
