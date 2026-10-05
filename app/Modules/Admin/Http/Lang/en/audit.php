<?php

/*
| Back office: the audit log (spec 021, US3). Read-only: no export, edit or delete.
| Every key here exists in ../ar/audit.php.
*/

return [
    'title' => 'Audit log',
    'subtitle' => 'Who did what, newest first. Entries are never changed.',
    'note' => 'Opening or filtering this page is recorded as audit_read. Entries are append-only.',
    'filters' => [
        'label' => 'Audit log filters',
        'event' => 'Event',
        'actor_type' => 'Actor type',
        'actor_id' => 'Actor ID',
        'subject_type' => 'Subject type',
        'subject_id' => 'Subject ID',
        'from' => 'From',
        'to' => 'To',
        'any' => 'Any',
        'apply' => 'Apply',
        'reset' => 'Reset',
    ],
    'columns' => [
        'when' => 'When (:zone)',
        'event' => 'Event',
        'actor' => 'Actor',
        'subject' => 'Subject',
        'origin' => 'Origin',
    ],
    'actor' => [
        'system' => 'System',
        'operator' => 'Operator',
        'user' => 'Person',
    ],
    'subject' => [
        'user' => 'Account',
        'wallet' => 'Wallet',
        'session' => 'Session',
        'subscription' => 'Subscription',
        'operator' => 'Operator',
        'payment_claim' => 'Claim',
        'plan' => 'Plan',
    ],
    'detail' => [
        'request_id' => 'Request ID',
        'entry_id' => 'Entry ID',
        'origin' => 'Origin',
    ],
    'expand' => 'Show or hide details of :event',
    'range' => 'Newest first · :from → :to',
    'empty' => 'No entries match.',
    'fix_filters' => 'Fix the filters above to see entries.',
    'errors' => [
        'range_too_long' => 'Choose a range of at most 400 days.',
        'range_inverted' => 'The end date must not be before the start.',
        'id' => 'Enter a full ID.',
        'date' => 'Enter a date like 2026-10-05.',
        'unknown' => 'Choose one of the listed values.',
    ],
];
