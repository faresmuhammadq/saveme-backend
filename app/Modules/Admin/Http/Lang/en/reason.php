<?php

/*
| Back office: the reason prompt (spec 006, FR-017). Every key here exists in ../ar/reason.php.
| Features add their action copy under `actions.<key>`.
*/

return [
    'question' => 'Why are you taking this action?',
    'attribution' => 'Written to the audit log as :who',
    'confirm' => 'Confirm',
    'errors' => [
        'blank' => 'Give a reason before confirming.',
        'too_long' => 'Keep the reason to :max characters.',
    ],
    'presets' => [
        'reference_verified' => 'Reference verified',
        'amount_matches' => 'Amount matches',
        'screenshot_checked' => 'Screenshot checked',
    ],
    'actions' => [
        'reenrolment' => [
            'approve' => [
                'title' => 'Approve the re-enrolment',
                'consequence' => 'Records your approval. When a second operator approves, the operator’s authenticator and recovery codes stop working and their sessions end.',
            ],
            'decline' => [
                'title' => 'Decline the re-enrolment',
                'consequence' => 'Closes the request. The operator can ask again.',
            ],
        ],
        'gallery' => [
            'approve' => [
                'title' => 'Approve the sample',
                'consequence' => 'Runs a sample action. Nothing is stored.',
            ],
        ],
        'fixture' => [
            'approve' => [
                'title' => 'Approve the sample',
                'consequence' => 'Records a sample approval.',
            ],
        ],
    ],
];
