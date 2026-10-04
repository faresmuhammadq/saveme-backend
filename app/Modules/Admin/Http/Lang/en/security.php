<?php

/*
| Back office: the operator's own security page and the re-enrolment requests
| page (spec 018). Every key here exists in ../ar/security.php.
*/

return [
    'title' => 'Security',
    'facts' => 'Your sign-in',
    'authenticator' => 'Authenticator',
    'linked' => 'Linked',
    'recovery_codes' => 'Recovery codes',
    'codes_left' => ':count of :total left',
    'last_sign_in' => 'Last sign-in',
    'actions' => 'Actions',
    'change_password' => 'Change password',
    'regenerate' => 'Generate new recovery codes',
    'replace_button' => 'Link a new authenticator',
    'password' => [
        'title' => 'Change password',
        'current' => 'Current password',
        'current_wrong' => 'That is not your current password.',
    ],
    'regenerate_confirm' => [
        'title' => 'Generate new recovery codes?',
        'body' => 'Your current codes stop working at once. The new codes are shown once.',
        'confirm' => 'Generate codes',
    ],
    'new_codes' => [
        'title' => 'Your new recovery codes',
        'done' => 'Done',
    ],
    'replace' => [
        'title' => 'Link a new authenticator',
        'keeps_working' => 'Your current authenticator keeps working until the new one is confirmed.',
    ],
    'toasts' => [
        'password_changed' => 'Password changed. Other sessions were signed out.',
        'codes_regenerated' => 'New recovery codes generated. Your old codes no longer work.',
        'authenticator_replaced' => 'New authenticator linked.',
        'reenrolment_approved' => 'Approval recorded. One more operator must approve.',
        'reenrolment_completed' => 'Approval recorded. The operator can now set up a new authenticator.',
        'reenrolment_declined' => 'Request declined.',
    ],
    'requests' => [
        'title' => 'Re-enrolment requests',
        'operator' => 'Operator',
        'opened' => 'Opened',
        'expires' => 'Expires',
        'approvals' => 'Approvals',
        'approve' => 'Approve',
        'decline' => 'Decline',
        'empty' => 'No requests are waiting.',
        'expired' => 'Expired',
        'refused' => [
            'own_request' => "You can't approve your own request.",
            'already_approved' => "You've already approved this request.",
            'expired' => 'This request has expired.',
            'closed' => 'This request is no longer open.',
            'already_open' => 'A request is already waiting.',
        ],
    ],
];
