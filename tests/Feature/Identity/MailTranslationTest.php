<?php

use App\Modules\Admin\Application\Mail\OperatorNotificationMail;
use App\Modules\Identity\Application\Mail\DeletionCancelledBySignInMail;
use App\Modules\Identity\Application\Mail\DeletionScheduledMail;
use App\Modules\Identity\Application\Mail\PasswordChangedMail;
use App\Modules\Identity\Application\Mail\ResetPasswordMail;
use App\Modules\Identity\Application\Mail\VerifyAddressMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;

/*
 * Every mail exists in Arabic and English, renders right-to-left in Arabic,
 * and uses Western numerals (P-14; spec 007, FR-070).
 */

test('every mail string exists in both languages', function () {
    $en = require app_path('Modules/Identity/Http/Lang/en/mail.php');
    $ar = require app_path('Modules/Identity/Http/Lang/ar/mail.php');

    expect(array_keys(Arr::dot($ar)))->toBe(array_keys(Arr::dot($en)));
});

test('each mail renders in Arabic, right to left, with Western digits and no missing key', function (Mailable $mail) {
    $html = $mail->locale('ar')->render();

    expect($html)->toContain('dir="rtl"')
        ->not->toContain('identity::mail.')
        ->not->toContain('admin::notifications.')
        ->toUseWesternDigitsOnly();
})->with([
    'verify' => fn () => new VerifyAddressMail('secret123'),
    'reset' => fn () => new ResetPasswordMail('secret123'),
    'password changed' => fn () => new PasswordChangedMail,
    'deletion scheduled' => fn () => new DeletionScheduledMail(new DateTimeImmutable('2026-10-26T09:00:00Z'), 'Africa/Cairo'),
    'deletion cancelled' => fn () => new DeletionCancelledBySignInMail('Pixel 8', new DateTimeImmutable('2026-09-26T09:00:00Z'), 'Africa/Cairo'),
    'operator' => fn () => new OperatorNotificationMail('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', '2026-09-26T08:30:00.000000Z'),
]);

test('only the two link mails carry a secret', function () {
    expect((new VerifyAddressMail('s3cret-verify'))->render())->toContain('s3cret-verify')
        ->and((new ResetPasswordMail('s3cret-reset'))->render())->toContain('s3cret-reset')
        ->and((new PasswordChangedMail)->render())->not->toContain('token=');
});
