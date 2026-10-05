<?php

return [

    'label' => 'Notifications',
    'unread' => ':count new',
    'mark_all_read' => 'Mark all read',
    'open_account' => 'Open account',
    'unread_item' => 'unread',
    'empty' => 'Nothing new.',
    'user' => 'User',

    'kinds' => [
        'deletion_cancelled_by_sign_in' => [
            'title' => 'Account deletion cancelled by sign-in',
            'body' => 'An account in its deletion grace period returned to active because someone signed in.',
        ],
        'account_purge_stalled' => [
            'title' => 'Account purge not finished',
            'body' => 'An account deletion has been running for more than 24 hours without finishing. It resumes every hour; check the queue if it persists.',
        ],
    ],

    'mail' => [
        'user' => 'User:',
        'when' => 'When:',
        'lookup' => 'Look the user up in the back office by this identifier. Every such read is audited.',
    ],

];
