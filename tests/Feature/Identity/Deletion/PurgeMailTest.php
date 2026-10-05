<?php

use App\Modules\Identity\Application\Deletion\PurgeAccount;
use App\Modules\Identity\Application\Deletion\RequestDeletion;
use App\Modules\Identity\Application\Mail\AccountDeletedMail;
use App\Modules\Identity\Application\Mail\DeletionReminderMail;
use App\Modules\Identity\Application\Mail\DeletionScheduledMail;
use App\Modules\Identity\Application\Mail\ExportReadyMail;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The mail of leaving (spec 022, FR-057, FR-059, FR-060): honest about Google
 * Play, sent once, from inside the purge, before the address goes, and naming
 * nothing private.
 */

test('the deletion confirmation says a Google Play subscription is not cancelled and suggests an export, in both languages', function (string $locale, string $playSentence, string $exportSentence) {
    app()->setLocale($locale);

    $html = (new DeletionScheduledMail(new DateTimeImmutable('2026-11-05'), 'Africa/Cairo'))->render();

    expect($html)->toContain($playSentence)->and($html)->toContain($exportSentence);
})->with([
    'English' => ['en', 'Deleting your account does not cancel a Google Play subscription', 'You can still export your data'],
    'Arabic' => ['ar', 'حذف حسابك لا يلغي اشتراك Google Play', 'ما زال بإمكانك تصدير بياناتك'],
]);

test('the completion mail is sent once, synchronously, while the address and key still exist', function () {
    $world = exportWorld();
    $seen = [];
    Event::listen(MessageSending::class, function (MessageSending $event) use (&$seen, $world): void {
        $seen[] = [
            'to' => array_map(fn ($address) => $address->getAddress(), $event->message->getTo()),
            'key_exists' => DB::table('user_keys')->where('user_id', $world['d']->id)->exists(),
            'address_exists' => DB::table('users')->where('id', $world['d']->id)->whereNotNull('email_ciphertext')->exists(),
            'subject' => $event->message->getSubject(),
        ];
    });
    Queue::fake();

    purgeAccountNow($world['d']->id);

    $completion = array_values(array_filter($seen, fn (array $mail): bool => $mail['to'] === ['ada@example.test']));
    expect($completion)->toHaveCount(1)
        ->and($completion[0]['key_exists'])->toBeTrue()
        ->and($completion[0]['address_exists'])->toBeTrue();
    foreach (Queue::pushedJobs() as $jobs) {
        foreach ($jobs as $pushed) {
            expect(serialize($pushed['job']))->not->toContain('ada@example.test');
        }
    }
    Queue::assertNotPushed(SendQueuedMailable::class);
});

test('a purge resumed after the mail step does not send it again', function () {
    $world = exportWorld();
    $sent = 0;
    Event::listen(MessageSent::class, function () use (&$sent): void {
        $sent++;
    });

    purgeWithCrashAfter($world['d']->id, PurgeStep::CompletionMail);

    expect($sent)->toBe(1);
});

test('a failed send stops the purge before the key is destroyed, and the retry sends it', function () {
    $world = exportWorld();
    $attempts = 0;
    Event::listen(MessageSending::class, function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('the mail server is down');
        }
    });

    expect(fn () => purgeAccountNow($world['d']->id))->toThrow(RuntimeException::class);

    expect(DB::table('user_keys')->where('user_id', $world['d']->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $world['d']->id)->value('state'))->toBe('purging');

    app(PurgeAccount::class)->run($world['d']->id);

    expect(DB::table('users')->where('id', $world['d']->id)->value('state'))->toBe('purged')
        ->and($attempts)->toBe(2);
});

test('the completion mail is in the profile language and no mail of this feature names a wallet, an amount or another person', function () {
    $world = exportWorld();
    $texts = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $texts[] = (new AccountDeletedMail)->render();
        $texts[] = (new DeletionReminderMail(new DateTimeImmutable('2026-11-05'), 'Africa/Cairo'))->render();
        $texts[] = (new ExportReadyMail('https://example.test/exports/x/file', 184320, new DateTimeImmutable('2026-10-07'), 'Africa/Cairo'))->render();
        $texts[] = (new DeletionScheduledMail(new DateTimeImmutable('2026-11-05'), 'Africa/Cairo'))->render();
    }

    foreach ($texts as $text) {
        foreach (['Family', 'Solo', 'Pat', 'Quinn', 'Ada', '2500', 'ada@example.test'] as $private) {
            expect($text)->not->toContain($private);
        }
    }
    expect($texts[0])->toContain('Google Play')->and($texts[4])->toContain('Google Play');

    $arabic = namedPerson('arabic@example.test', null, 'ar');
    Mail::fake();
    purgeAccountNow($arabic->id);
    Mail::assertSent(AccountDeletedMail::class, fn (AccountDeletedMail $mail): bool => $mail->hasTo('arabic@example.test') && $mail->locale === 'ar');
});

test('asking for the deletion still queues the confirmation with the new sentences', function () {
    Mail::fake();
    $user = person();

    app(RequestDeletion::class)->handle($user->id, 'correct horse');

    Mail::assertQueued(DeletionScheduledMail::class, fn (DeletionScheduledMail $mail): bool => str_contains($mail->render(), 'Google Play'));
});
