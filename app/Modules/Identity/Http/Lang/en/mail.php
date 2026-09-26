<?php

/*
 * Identity mail (spec 007, FR-070). Keys mirror lang ar/mail.php exactly.
 */
return [

    'greeting' => 'Hello,',
    'signature' => '— The :app team',

    'verify_address' => [
        'subject' => 'Welcome to :app — confirm your email address',
        'intro' => 'Thanks for creating an account. Confirm this is your email address so you can add entries to your wallets.',
        'action' => 'Confirm my email address',
        'expiry' => 'This link works once and expires in 24 hours. You can ask for a new one in the app.',
        'ignore' => 'If you did not create an account, you can ignore this email.',
    ],

    'reset_password' => [
        'subject' => 'Reset your :app password',
        'intro' => 'We received a request to reset your password.',
        'action' => 'Choose a new password',
        'expiry' => 'This link works once and expires in 60 minutes. Resetting signs out every device.',
        'ignore' => 'If you did not ask for this, ignore this email. Your password has not changed.',
    ],

    'password_changed' => [
        'subject' => 'Your :app password was changed',
        'intro' => 'Your password was just changed. Every other device was signed out.',
        'not_you' => 'If this was not you, reset your password now from the sign-in screen.',
    ],

    'deletion_scheduled' => [
        'subject' => 'Your :app account will be deleted',
        'intro' => 'Your account is scheduled for deletion on :date.',
        'keep' => 'Changed your mind? Sign in again, or cancel the deletion in the app, before that date. Nothing will be lost.',
        'export' => 'You can still export your data until then, and doing so does not change the date.',
    ],

    'deletion_cancelled_by_sign_in' => [
        'subject' => 'Your :app account deletion was cancelled',
        'intro' => 'Someone signed in to your account from :device at :time, so the scheduled deletion was cancelled and your account is active again.',
        'not_you' => 'If this was not you, reset your password now: that signs out every device. Then request the deletion again if you still want it.',
    ],

];
