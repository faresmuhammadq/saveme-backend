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
        'google_play' => 'Deleting your account does not cancel a Google Play subscription. If you have one, cancel it in Google Play.',
    ],

    'deletion_reminder' => [
        'subject' => 'Your :app account will be deleted soon',
        'intro' => 'A reminder: your account will be deleted on :date.',
        'keep' => 'To stop it, sign in again or cancel the deletion in the app before then. Nothing will be lost.',
        'export' => 'If you want a copy of your data, request an export in the app first.',
        'google_play' => 'Deleting your account does not cancel a Google Play subscription. If you have one, cancel it in Google Play.',
    ],

    'deletion_cancelled_by_sign_in' => [
        'subject' => 'Your :app account deletion was cancelled',
        'intro' => 'Someone signed in to your account from :device at :time, so the scheduled deletion was cancelled and your account is active again.',
        'not_you' => 'If this was not you, reset your password now: that signs out every device. Then request the deletion again if you still want it.',
    ],

    'export_ready' => [
        'subject' => 'Your :app export is ready',
        'intro' => 'The copy of your data you asked for is ready to download.',
        'action' => 'Download my export',
        'details' => 'It is :size and the link works until :expiry. After that the file is deleted, and you can ask for a new one in the app.',
        'ignore' => 'If you did not ask for this, change your password: only someone signed in to your account can request an export.',
    ],

    'account_deleted' => [
        'subject' => 'Your :app account has been deleted',
        'intro' => 'Your account has been deleted: your sign-in, your personal records and the key that protected them are gone, and this is the last email we will send you.',
        'shared' => 'Wallets you shared kept their history for the people still in them; your entries there no longer lead to you.',
        'google_play' => 'Deleting your account does not cancel a Google Play subscription. If you have one, cancel it in Google Play.',
        'again' => 'You are welcome to create a new account with this address at any time.',
    ],

];
