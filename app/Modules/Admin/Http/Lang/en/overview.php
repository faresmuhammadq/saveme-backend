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
        'paid_instapay' => 'Paid · InstaPay',
        'granted_manual' => 'Granted · Manual',
        'in_grace' => 'In grace',
        'shared_wallets' => 'Shared wallets',
    ],
    'attention' => [
        'title' => 'Needs attention',
        'nothing' => 'Nothing needs attention',
        'claims' => '{1} :count claim awaiting review|[2,*] :count claims awaiting review',
        'oldest' => 'oldest',
        'reenrolments' => '{1} :count re-enrolment request open|[2,*] :count re-enrolment requests open',
    ],
];
