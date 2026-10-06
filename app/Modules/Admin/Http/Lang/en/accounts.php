<?php

/*
| Back office: finding an account and the account page (spec 021, US2).
| Counts and facts only: no names, amounts, balances or notes ever appear here.
| Every key here exists in ../ar/accounts.php.
*/

return [
    'title' => 'Accounts',
    'subtitle' => 'Find one person by exact email or account ID. Every look is recorded.',
    'find' => [
        'label' => 'Exact email or account ID',
        'hint' => 'Partial searches aren’t supported.',
        'submit' => 'Find',
        'no_match' => 'No account matches.',
        'empty' => 'Enter an email address or an account ID.',
    ],
    'budget_spent' => 'Too many lookups. Try again in :minutes minutes.',
    'page' => [
        'title' => 'Account',
        'found_by' => 'Found by :address',
        'account_id' => 'Account ID',
        'created' => 'Created :date',
        'privacy_note' => 'Names, balances, amounts and notes are never shown here.',
        'verified' => 'Email verified',
        'unverified' => 'Email not verified',
        'audit_link' => 'Audit entries about this account',
        'purged_note' => 'This account was deleted. Nothing else is kept.',
        'purging_note' => 'This account is being deleted. Nothing else is shown.',
    ],
    'state' => [
        'active' => 'Active',
        'pending_deletion' => 'Deletion pending · :date',
        'purging' => 'Deleting',
        'purged' => 'Deleted',
    ],
    'plan' => [
        'title' => 'Plan',
        'governing' => 'Governing plan',
        'allowed' => 'Allowed',
        'not_allowed' => 'Not allowed',
        'unlimited' => 'Unlimited',
    ],
    'subscriptions' => [
        'title' => 'Subscriptions',
        'none' => 'No subscriptions. The free plan applies.',
        'governing' => 'Governing',
        'columns' => [
            'plan' => 'Plan',
            'channel' => 'Channel',
            'status' => 'Status',
            'started' => 'Started',
            'ends' => 'Ends',
            'grace' => 'Grace until',
            'created' => 'Created',
            'actions' => 'Actions',
        ],
        'no_end' => 'No end',
        'no_grace' => '—',
        'channels' => [
            'google_play' => 'Google Play',
            'instapay' => 'InstaPay',
            'manual' => 'Manual',
        ],
        'statuses' => [
            'active' => 'Active',
            'grace' => 'Grace',
            'expired' => 'Expired',
            'revoked' => 'Revoked',
        ],
    ],
    'counts' => [
        'title' => 'Counts',
        'wallets_owned' => 'Wallets owned',
        'wallets_joined' => 'Wallets joined',
        'other_members' => 'Other members in owned wallets',
        'devices' => 'Signed-in devices',
        'push_devices' => 'Devices that can receive notifications',
    ],
    'actions' => [
        'grant' => 'Grant access…',
        'extend' => 'Extend…',
        'revoke' => 'Revoke…',
        'extend_label' => 'Extend :plan',
        'revoke_label' => 'Revoke :plan',
    ],
    'reason_hint' => 'Don’t include personal details. The reason is kept in the audit log for 400 days.',
    'grant_dialog' => [
        'title' => 'Grant access',
        'body' => 'Creates a manual subscription for this person. It is recorded with your name.',
        'plan' => 'Plan',
        'duration' => 'Duration',
        'durations' => [
            '30' => '30 days',
            '90' => '90 days',
            '365' => '365 days',
            'custom' => 'Custom days',
            'life' => 'Lifetime',
        ],
        'days' => 'Number of days (1 to 3,650)',
        'reason' => 'Reason',
        'confirm' => 'Grant',
    ],
    'extend_dialog' => [
        'title' => 'Extend this subscription',
        'body' => 'Adds days to the end of a manual subscription.',
        'days' => 'Days to add (1 to 3,650)',
        'reason' => 'Reason',
        'confirm' => 'Extend',
    ],
    'revoke_dialog' => [
        'title' => 'Revoke this subscription',
        'body' => 'Ends access from this subscription now. This cannot be undone.',
        'reason' => 'Reason',
        'confirm' => 'Revoke',
    ],
    'messages' => [
        'granted' => 'Access granted.',
        'extended' => 'Subscription extended.',
        'revoked' => 'Subscription revoked.',
    ],
    'errors' => [
        'plan_not_grantable' => 'This plan can’t be granted.',
        'not_extendable' => 'Only manual subscriptions with an end date can be extended.',
        'not_revocable' => 'Google Play subscriptions are managed by Google Play.',
        'account_not_active' => 'This account isn’t active.',
        'subscription_revoked' => 'This subscription was already revoked and can’t be extended.',
        'subscription_not_found' => 'This subscription no longer exists.',
        'invalid_days' => 'Enter a whole number of days from 1 to 3,650.',
        'invalid_reason' => 'Give a reason of 1 to 500 characters.',
    ],
];
