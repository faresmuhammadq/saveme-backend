<?php

/*
 * Push text (spec 023, FR-021, P-14): fixed, generic, in the profile language.
 * No placeholder, no name, no amount, no number, no date: a lock screen is
 * public. A test fails if any string here gains one.
 */
return [
    'occurrence_due' => [
        'title' => 'Something is due today',
        'body' => 'A recurring item needs your confirmation.',
    ],
    'occurrence_auto_confirm_refused' => [
        'title' => 'A recurring item needs you',
        'body' => 'It could not be confirmed automatically. Open to review it.',
    ],
    'occurrences_due_summary' => [
        'title' => 'Items need you',
        'body' => 'Several recurring items are due. Open to review them.',
    ],
    'budget_exceeded' => [
        'title' => 'A budget is over its limit',
        'body' => 'Open to see where your spending stands.',
    ],
    'invitation_received' => [
        'title' => 'You have been invited to a shared wallet',
        'body' => 'Check your email to respond to the invitation.',
    ],
    'member_role_changed' => [
        'title' => 'Your role in a wallet changed',
        'body' => 'Open to see what you can do there now.',
    ],
    'member_removed' => [
        'title' => 'You were removed from a wallet',
        'body' => 'It is no longer in your list.',
    ],
    'wallet_ownership_received' => [
        'title' => 'You now own a wallet',
        'body' => 'Ownership of a shared wallet was passed to you.',
    ],
    'subscription_ending' => [
        'title' => 'Your plan ends soon',
        'body' => 'Open to review your plan.',
    ],
    'subscription_lapsed' => [
        'title' => 'Your plan has ended',
        'body' => 'Open to review your plan.',
    ],
    'export_ready' => [
        'title' => 'Your export is ready',
        'body' => 'Open to download it.',
    ],
    'deletion_reminder' => [
        'title' => 'Your account will be deleted soon',
        'body' => 'Open to review or cancel the deletion.',
    ],
];
