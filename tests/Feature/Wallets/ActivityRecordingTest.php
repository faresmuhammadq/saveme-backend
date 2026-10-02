<?php

use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Wallets\Application\Activity\ActivityPrune;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
 * Activity Entries are written by the services that own the write, inside its
 * unit of work, once per applied write (spec 016, US8; FR-027, FR-028; R-12;
 * ADR-0020): replays write none, nothing protected is stored.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    Queue::fake();
    [$this->owner, $this->ownerCredential, $this->wallet] = plusOwner();
    $this->editor = person('editor@example.test');
    addMember($this->wallet->id, $this->editor->id, 'editor');
});

/**
 * "action:subject_type" of every entry, oldest first.
 *
 * @return list<string>
 */
function activityKeys(?string $walletId = null): array
{
    return WalletActivity::query()
        ->when($walletId !== null, fn ($query) => $query->where('wallet_id', $walletId))
        ->orderBy('occurred_at')->orderBy('id')->get()
        ->map(fn (WalletActivity $entry): string => $entry->action.':'.$entry->subject_type)->all();
}

test('creating and deleting a wallet record nothing', function () {
    $user = person('solo@example.test');
    $saved = app(WalletService::class)->save($user->id, (string) Str::uuid7(), walletValues(), 0, presentWallet());

    app(WalletService::class)->delete($user->id, $saved->view->wallet->id, $saved->view->wallet->version, presentWallet());

    expect(WalletActivity::query()->count())->toBe(0);
});

test('a wallet edit, an archive and an ownership transfer record one entry each, a replay none', function () {
    $service = app(WalletService::class);
    $version = Wallet::query()->findOrFail($this->wallet->id)->version;

    $service->patch($this->owner->id, $this->wallet->id, ['name' => 'Renamed'], $version, presentWallet());
    $service->patch($this->owner->id, $this->wallet->id, ['name' => 'Renamed'], $version + 1, presentWallet());
    $service->archive($this->owner->id, $this->wallet->id, $version + 1, presentWallet());
    $service->archive($this->owner->id, $this->wallet->id, $version + 2, presentWallet());
    $service->transferOwnership($this->owner->id, $this->wallet->id, $this->editor->id, $version + 2, presentWallet());
    $service->transferOwnership($this->owner->id, $this->wallet->id, $this->editor->id, $version + 3, presentWallet());

    expect(activityKeys())->toBe(['edited:wallet', 'archived:wallet', 'ownership_transferred:wallet']);
    $transfer = WalletActivity::query()->where('action', 'ownership_transferred')->sole();
    expect($transfer->actor_user_id)->toBe($this->owner->id)->and($transfer->role)->toBe('owner')->and($transfer->subject_id)->toBe($this->wallet->id);
});

test('a transaction create, edit, delete and restore record one entry each; a replay none', function () {
    $service = app(TransactionService::class);
    $id = (string) Str::uuid7();

    saveTransaction($this->editor->id, $this->wallet->id, $id, transactionBody());
    saveTransaction($this->editor->id, $this->wallet->id, $id, transactionBody(version: 1));
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody(['amount_minor' => 4000], version: 1));
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody(['amount_minor' => 4000], version: 2));
    $service->delete($this->owner->id, $this->wallet->id, $id, 2, presentTransaction());
    $service->delete($this->owner->id, $this->wallet->id, $id, 3, presentTransaction());
    $service->restore($this->editor->id, $this->wallet->id, $id, 3, presentTransaction());
    $service->restore($this->editor->id, $this->wallet->id, $id, 4, presentTransaction());

    $entries = WalletActivity::query()->orderBy('occurred_at')->orderBy('id')->get();
    expect($entries->map(fn ($entry): string => $entry->action)->all())->toBe(['created', 'edited', 'deleted', 'restored'])
        ->and($entries->pluck('actor_user_id')->all())->toBe([$this->editor->id, $this->owner->id, $this->owner->id, $this->editor->id])
        ->and($entries->pluck('subject_type')->unique()->all())->toBe(['transaction'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$id]);
});

test('a patch of a transaction records an edit, and a no-change patch none', function () {
    $transaction = recordTransaction($this->editor->id, $this->wallet->id)->transaction;
    $service = app(TransactionService::class);

    $service->patch($this->editor->id, $this->wallet->id, $transaction->id, ['description' => 'Dinner'], 1, presentTransaction());
    $service->patch($this->editor->id, $this->wallet->id, $transaction->id, ['description' => 'Dinner'], 2, presentTransaction());

    expect(activityKeys())->toBe(['created:transaction', 'edited:transaction']);
});

test('the sync batch records the same entries as the single writes', function () {
    $id = (string) Str::uuid7();
    $body = ['items' => [
        syncItem('transaction', 'upsert', $id, transactionBody(), $this->wallet->id),
        syncItem('transaction', 'upsert', $id, transactionBody(['amount_minor' => 9000], version: 1), $this->wallet->id),
        syncItem('transaction', 'delete', $id, ['version' => 2], $this->wallet->id),
    ]];

    walletCall('POST', $this->ownerCredential, '/sync/batch', $body)->assertOk();

    expect(activityKeys())->toBe(['created:transaction', 'edited:transaction', 'deleted:transaction']);
});

test('a transfer is recorded in each wallet it touches', function () {
    $other = ownedWallet($this->owner->id);

    recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $other->id]));

    expect(activityKeys($this->wallet->id))->toBe(['created:transaction'])->and(activityKeys($other->id))->toBe(['created:transaction']);
});

test('a recurring rule create, edit and delete record one entry each, a replay none; materialising none', function () {
    $service = app(RecurringRuleService::class);
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody())->rule;
    saveRecurringRule($this->editor->id, $this->wallet->id, $rule->id, recurringRuleBody(version: 1));
    saveRecurringRule($this->editor->id, $this->wallet->id, $rule->id, recurringRuleBody(['amount_minor' => 500000], version: 1));
    $service->materialise($this->editor->id, $this->wallet->id, $rule->id, presentRule());
    $service->delete($this->owner->id, $this->wallet->id, $rule->id, 2, presentRule());
    $service->delete($this->owner->id, $this->wallet->id, $rule->id, 3, presentRule());

    expect(activityKeys())->toBe(['created:recurring_rule', 'edited:recurring_rule', 'deleted:recurring_rule']);
});

test('confirming, skipping and editing an occurrence record one entry each; the transaction it produces none of its own', function () {
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    [$first, $second, $third] = occurrencesOf($rule->id)->all();
    DB::table('wallet_activity')->delete();

    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $first->id, confirmBody($first));
    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $second->id, occurrenceBody($second, ['status' => 'skipped']));
    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $third->id, occurrenceBody($third, ['amount_minor' => 1234]));
    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $third->id, occurrenceBody($third));

    expect(activityKeys())->toBe(['confirmed:occurrence', 'skipped:occurrence', 'edited:occurrence'])
        ->and(Transaction::query()->count())->toBe(1);
});

test('an automatic confirmation records confirmed with no actor', function () {
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]))->rule;
    DB::table('wallet_activity')->delete();

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    $entry = WalletActivity::query()->sole();
    expect($entry->action)->toBe('confirmed')
        ->and($entry->subject_type)->toBe('occurrence')
        ->and($entry->actor_user_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
});

test('membership and invitation acts record joined, role changes, removal, leaving, invited and revoked', function () {
    $invitee = person('invitee@example.test');
    $inviteeCredential = signInAs('invitee@example.test')->json('data.credential');
    $pending = pendingInvitation($this->owner->id, $this->wallet->id, null);
    $members = '/wallets/'.$this->wallet->id.'/members/';

    sendInvitation($this->ownerCredential, $this->wallet->id, ['invitee_email' => null])->assertCreated();
    walletCall('DELETE', $this->ownerCredential, '/wallets/'.$this->wallet->id.'/invitations/'.$pending['id'], ['version' => 1])->assertOk();
    $link = pendingInvitation($this->owner->id, $this->wallet->id, null);
    respondToInvitation($inviteeCredential, 'accept', $link['token'])->assertOk();
    walletCall('PATCH', $this->ownerCredential, $members.$invitee->id, ['role' => 'viewer', 'version' => 1])->assertOk();
    walletCall('POST', $inviteeCredential, '/wallets/'.$this->wallet->id.'/leave', ['version' => 2])->assertOk();
    walletCall('DELETE', $this->ownerCredential, $members.$this->editor->id, ['version' => 1])->assertOk();

    expect(activityKeys())->toBe(['invited:invitation', 'invitation_revoked:invitation', 'joined:member', 'role_changed:member', 'left:member', 'removed:member']);
});

test('no row holds an amount, note, name or address: only the whitelisted identifier columns exist', function () {
    expect(Schema::getColumnListing('wallet_activity'))->toBe(['id', 'wallet_id', 'occurred_at', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'role']);

    recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['description' => 'Secret dinner', 'memo' => 'hush', 'counterparty' => 'Karim', 'amount_minor' => 98765]));
    sendInvitation($this->ownerCredential, $this->wallet->id, ['invitee_email' => 'private.person@example.test'])->assertCreated();

    $text = DB::table('wallet_activity')->get()->toJson();
    expect($text)->not->toContain('Secret dinner')->not->toContain('hush')->not->toContain('Karim')->not->toContain('98765')->not->toContain('private.person');
});

test('the prune removes entries older than 90 days, in batches, and keeps the rest', function () {
    recordTransaction($this->editor->id, $this->wallet->id);
    WalletActivity::query()->update(['occurred_at' => '2026-06-01 10:00:00']);
    recordTransaction($this->editor->id, $this->wallet->id);
    $recent = WalletActivity::query()->where('occurred_at', '>', '2026-09-01')->count();

    $removed = app(TombstonePurgeRun::class)->handle();

    expect($removed['wallets_activity'])->toBe(1)
        ->and(WalletActivity::query()->count())->toBe($recent)
        ->and(app(TombstonePurgeRun::class)->handle()['wallets_activity'])->toBe(0);
});

test('the prune works through more than one batch and clamps a retention under 90 days', function () {
    config(['sharing.activity_retention_days' => 1]);
    foreach (range(1, 5) as $n) {
        recordTransaction($this->editor->id, $this->wallet->id);
    }
    WalletActivity::query()->limit(3)->get()->each(fn ($entry) => $entry->forceFill(['occurred_at' => '2026-06-01 10:00:00'])->save());
    WalletActivity::query()->where('occurred_at', '>', '2026-09-01')->update(['occurred_at' => '2026-09-01 10:00:00']);

    $removed = app(ActivityPrune::class)->prune(null, 2);

    // 90 days before 2026-10-05 is 2026-07-07: the June entries go, the September ones stay despite retention 1.
    expect($removed)->toBe(3)->and(WalletActivity::query()->count())->toBe(2);
});

test('the activity of a wallet goes with the wallet when it is purged', function () {
    $solo = ownedWallet($this->owner->id);
    recordTransaction($this->owner->id, $solo->id);
    DB::table('transactions')->where('wallet_id', $solo->id)->delete();
    DB::table('postings')->where('wallet_id', $solo->id)->delete();
    DB::table('wallet_members')->where('wallet_id', $solo->id)->delete();
    DB::table('category_references')->where('wallet_id', $solo->id)->delete();
    DB::table('wallets')->where('id', $solo->id)->delete();

    expect(WalletActivity::query()->where('wallet_id', $solo->id)->count())->toBe(0);
});
