<?php

/*
| Back office: the platform overview (spec 021, US5). Every key here exists in ../ar/overview.php.
*/

return [
    'title' => 'Platform overview',
    'subtitle' => 'Counts only. Figures under 10 people are shown as “fewer than 10”.',
    'fewer_than_10' => 'fewer than 10',
    'figures' => [
        'accounts' => 'Accounts',
        'verified' => 'Verified',
        'pending_deletion' => 'Deletion pending',
        'new_7_days' => 'New · 7 days',
        'new_30_days' => 'New · 30 days',
        'paid_google_play' => 'Paid · Google Play',
        'test_google_play' => 'Test · Google Play',
        'paid_instapay' => 'Paid · InstaPay',
        'granted_manual' => 'Granted · Manual',
        'in_grace' => 'In grace',
        'shared_wallets' => 'Shared wallets',
    ],
    'push' => [
        'title' => 'Push, last 24 hours',
        'hint' => 'Counts only: never a person, a device or what a notification said.',
        'created' => 'Created',
        'sent' => 'Sent',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
        'pruned' => 'Registrations pruned',
    ],
    'play' => [
        'title' => 'Google Play, last 24 hours',
        'hint' => 'Counts only: never a purchase, an order or a person.',
        'received' => 'Notifications received',
        'processed' => 'Processed',
        'ignored' => 'Ignored',
        'failed' => 'Failed',
        'rejected' => 'Pushes rejected',
        'verified' => 'Purchases verified',
        'unacknowledged' => 'Unacknowledged over 24 hours',
        'reasons' => [
            'malformed' => 'Malformed token',
            'algorithm' => 'Wrong algorithm',
            'signature' => 'Signature',
            'issuer' => 'Issuer',
            'audience' => 'Audience',
            'email' => 'Sender',
            'email_unverified' => 'Sender unverified',
            'time' => 'Issued-at window',
            'expired' => 'Expired',
        ],
    ],
    'attention' => [
        'title' => 'Needs attention',
        'nothing' => 'Nothing needs attention',
        'claims' => '{1} :count claim awaiting review|[2,*] :count claims awaiting review',
        'oldest' => 'oldest',
        'reenrolments' => '{1} :count re-enrolment request open|[2,*] :count re-enrolment requests open',
    ],
];
