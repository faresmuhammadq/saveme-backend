<?php

/*
 * Sharing mail (spec 016, FR-005, P-14). Keys mirror lang ar/mail.php exactly.
 * The mail names the inviter and the role, never the wallet and never an amount.
 */
return [

    'greeting' => 'Hello,',
    'signature' => '— The :app team',

    'roles' => [
        'editor' => 'an editor, who can add and change entries',
        'viewer' => 'a viewer, who can see entries',
    ],

    'invitation_registered' => [
        'subject' => 'You have been invited to a shared wallet on :app',
        'intro_named' => ':inviter invited you to join a shared wallet on :app as :role.',
        'intro_neutral' => 'You have been invited to join a shared wallet on :app as :role.',
        'action' => 'Open the invitation',
        'expiry' => 'This link works once and expires in 7 days.',
        'ignore' => 'If you were not expecting this, you can ignore this email.',
    ],

    'invitation_unregistered' => [
        'subject' => 'You have been invited to a shared wallet on :app',
        'intro_named' => ':inviter invited you to join a shared wallet on :app as :role.',
        'intro_neutral' => 'You have been invited to join a shared wallet on :app as :role.',
        'create_account' => 'To join, first create a :app account with this email address, then open the invitation.',
        'action' => 'Open the invitation',
        'expiry' => 'This link works once and expires in 7 days.',
        'ignore' => 'If you were not expecting this, you can ignore this email.',
    ],

];
