<?php

use App\Modules\Identity\Application\Jobs\SendDeletionReminderMail;
use App\Modules\Identity\Application\Jobs\SendExportReadyMail;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The purge writes one honest entry and nothing it must not (spec 022, FR-055,
 * FR-056, SC-006): counts, never content, in audit, activity and logs alike.
 */

beforeEach(function () {
    Mail::fake();
    $this->world = exportWorld();
    $this->d = $this->world['d'];
    $this->secrets = ['ada@example.test', 'Ada Lovelace', 'Ada', 'Family', 'Solo', 'Pat Wallet', 'Groceries', 'Ada lunch', 'Pat dinner', 'Solo coffee', '2500', 'invitee@example.test'];
    pendingInvitation($this->d->id, $this->world['s']->id, 'invitee@example.test');
    $this->knownAudit = DB::table('audit_entries')->pluck('id')->all();
    $this->knownActivity = DB::table('wallet_activity')->pluck('id')->all();
});

test('exactly one deletion_completed entry, written by the system about the user, carrying counts only', function () {
    purgeAccountNow($this->d->id);

    $entries = AuditEntry::query()->where('event', 'deletion_completed')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('system')
        ->and($entries[0]->actor_id)->toBeNull()
        ->and($entries[0]->subject_type)->toBe('user')
        ->and($entries[0]->subject_id)->toBe($this->d->id)
        ->and(array_keys($entries[0]->detail))->toEqualCanonicalizing(['wallets_transferred', 'wallets_deleted', 'memberships_ended'])
        ->and(array_filter($entries[0]->detail, fn (mixed $count): bool => ! is_int($count)))->toBe([])
        ->and($entries[0]->detail['wallets_transferred'])->toBe(1)
        ->and($entries[0]->detail['wallets_deleted'])->toBe(1)
        ->and($entries[0]->detail['memberships_ended'])->toBeGreaterThanOrEqual(2);
});

test('nothing the purge adds to the audit log or the wallet activity contains an excluded value', function () {
    purgeAccountNow($this->d->id);

    $audit = DB::table('audit_entries')->whereNotIn('id', $this->knownAudit)->get();
    $activity = DB::table('wallet_activity')->whereNotIn('id', $this->knownActivity)->get();
    expect($audit->count())->toBeGreaterThan(3)->and($activity->count())->toBeGreaterThan(0);

    $written = json_encode([$audit, $activity]);
    foreach ($this->secrets as $secret) {
        expect($written)->not->toContain($secret);
    }
});

test('nothing logged by the purge carries an address, a name, a wallet or category name, an amount or a note', function () {
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$records): void {
        $records[] = $log->message.' '.json_encode($log->context);
    });

    purgeAccountNow($this->d->id);

    $logged = implode("\n", $records);
    expect($logged)->toContain('account_purge')->and($logged)->toContain($this->d->id);
    foreach ($this->secrets as $secret) {
        expect($logged)->not->toContain($secret);
    }
});

test('no queued job holds the address, and the completion mail is not queued at all', function () {
    Queue::fake();
    Mail::fake();

    purgeAccountNow($this->d->id);

    foreach (Queue::pushedJobs() as $jobs) {
        foreach ($jobs as $pushed) {
            expect(serialize($pushed['job']))->not->toContain('ada@example.test');
        }
    }
    Mail::assertNothingQueued();
    Mail::assertSentCount(1);
});

test('the mail jobs that carry an address are encrypted at rest', function () {
    foreach ([
        SendExportReadyMail::class,
        SendDeletionReminderMail::class,
    ] as $job) {
        expect(is_subclass_of($job, ShouldBeEncrypted::class))->toBeTrue($job);
    }
});
