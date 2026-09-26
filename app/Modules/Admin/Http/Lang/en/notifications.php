<?php

return [

    'label' => 'Notifications',
    'unread' => ':count new',
    'empty' => 'Nothing new.',
    'user' => 'User',

    'kinds' => [
        'deletion_cancelled_by_sign_in' => [
            'title' => 'Account deletion cancelled by sign-in',
            'body' => 'An account in its deletion grace period returned to active because someone signed in.',
        ],
    ],

    'mail' => [
        'user' => 'User:',
        'when' => 'When:',
        'lookup' => 'Look the user up in the back office by this identifier. Every such read is audited.',
    ],

];
