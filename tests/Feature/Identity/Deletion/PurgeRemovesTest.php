<?php

use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Application\Deletion\StartDuePurges;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * What the purge removes and what it keeps (spec 022, FR-046 to FR-050; the
 * table in data-model.md).
 */

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Queue::fake();
    $this->world = exportWorld();
    $this->d = $this->world['d'];

    $this->unused = ownedCategory($this->d->id, ['name' => 'Never used']);
    $this->budget = createBudget($this->d->id, ['category_id' => null]);
    $this->usedBudget = createBudget($this->d->id, ['category_id' => $this->world['groceries']->id]);
    DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->d->id, 'start_day' => 5, 'applies_from' => null, 'created_at' => now()]);
    linkFor($this->d->id, LinkKind::ResetPassword);
    DB::table('sessions')->insert(['id' => 'web-session', 'user_id' => $this->d->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    signInAs('ada@example.test');
    signInAs('ada@example.test', 'wrong password');
    $this->lookup = DB::table('users')->where('id', $this->d->id)->value('email_lookup');
    $this->manual = Subscription::factory()->create(['user_id' => $this->d->id, 'provider' => 'manual']);
    $this->play = Subscription::factory()->provider('google_play')->create(['user_id' => $this->d->id]);
    $this->export = DataExport::factory()->create(['user_id' => $this->d->id]);
    app(GenerateExport::class)->run($this->export->id);
    DB::table('deletion_successor_choices')->insert(['user_id' => $this->d->id, 'wallet_id' => $this->world['w']->id, 'member_user_id' => $this->world['p']->id, 'chosen_at' => now()]);
});

test('the users row is a husk: purged, purge instant, no address, lookup or password, counters kept', function () {
    $before = DB::table('users')->where('id', $this->d->id)->first();

    purgeAccountNow($this->d->id);

    $husk = DB::table('users')->where('id', $this->d->id)->first();
    expect($husk->state)->toBe('purged')
        ->and($husk->purged_at)->not->toBeNull()
        ->and($husk->email_ciphertext)->toBeNull()->and($husk->email_lookup)->toBeNull()->and($husk->password_hash)->toBeNull()
        ->and($husk->deletion_requested_at)->toBeNull()->and($husk->deletion_scheduled_for)->toBeNull()
        ->and((int) $husk->change_seq)->toBeGreaterThanOrEqual((int) $before->change_seq)
        ->and((int) $husk->purged_change_seq)->toBeGreaterThanOrEqual((int) $before->purged_change_seq);
});

test('every dependant of the identity is gone, and the data key with it', function () {
    purgeAccountNow($this->d->id);

    foreach (['profiles', 'budget_start_days', 'identity_links', 'device_sessions', 'user_keys', 'data_exports', 'deletion_successor_choices', 'account_purges', 'sessions'] as $table) {
        expect(DB::table($table)->where('user_id', $this->d->id)->exists())->toBeFalse($table);
    }
    expect(DB::table('sign_in_failures')->where('lookup', $this->lookup)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([]);
});

test('budgets and unreferenced categories are removed with the user scope\'s horizon raised past them; used categories are archived and kept', function () {
    $stamps = collect([
        DB::table('budgets')->where('user_id', $this->d->id)->max('change_seq'),
        DB::table('categories')->where('id', $this->unused->id)->value('change_seq'),
    ]);

    purgeAccountNow($this->d->id);

    $horizon = (int) DB::table('users')->where('id', $this->d->id)->value('purged_change_seq');
    expect(DB::table('budgets')->where('user_id', $this->d->id)->exists())->toBeFalse()
        ->and(Category::query()->find($this->unused->id))->toBeNull()
        ->and($horizon)->toBeGreaterThan((int) $stamps->max());

    $groceries = Category::query()->find($this->world['groceries']->id);
    expect($groceries)->not->toBeNull()
        ->and($groceries->archived_at)->not->toBeNull()
        ->and($groceries->deleted_at)->toBeNull()
        ->and($groceries->user_id)->toBe($this->d->id);
});

test('subscriptions: manual ones end as a revocation ends them, google_play is left alone, every row is stamped', function () {
    purgeAccountNow($this->d->id);

    $manual = DB::table('subscriptions')->find($this->manual->id);
    $play = DB::table('subscriptions')->find($this->play->id);
    expect($manual->status)->toBe('revoked')->and($manual->ended_by_account_purge_at)->not->toBeNull()
        ->and($play->status)->toBe('active')->and($play->ended_by_account_purge_at)->not->toBeNull();
});

test('audit, activity, category references, operator notifications and claims are untouched', function () {
    $claim = PaymentClaim::factory()->create();
    DB::table('operator_notifications')->insert([
        'id' => (string) Str::uuid7(), 'kind' => 'deletion_cancelled_by_sign_in', 'subject_user_id' => $this->d->id,
        'occurred_at' => now(), 'payload' => '{}', 'source_event_id' => (string) Str::uuid7(), 'created_at' => now(),
    ]);
    $audit = DB::table('audit_entries')->pluck('id')->all();
    $activity = DB::table('wallet_activity')->pluck('id')->all();
    $references = DB::table('category_references')->count();

    purgeAccountNow($this->d->id);

    expect(DB::table('audit_entries')->whereIn('id', $audit)->count())->toBe(count($audit))
        ->and(DB::table('wallet_activity')->whereIn('id', $activity)->count())->toBe(count($activity))
        ->and(DB::table('category_references')->count())->toBeGreaterThanOrEqual($references - 2)
        ->and(DB::table('operator_notifications')->count())->toBe(1)
        ->and(DB::table('payment_claims')->where('id', $claim->id)->exists())->toBeTrue();
});

test('the old address no longer signs in and registers as a brand new account', function () {
    purgeAccountNow($this->d->id);

    signInAs('ada@example.test')->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/register', ['email' => 'ada@example.test', 'password' => 'correct horse'])->assertStatus(202);

    $fresh = DB::table('users')->where('state', 'active')->where('email_lookup', $this->lookup)->first();
    expect($fresh)->not->toBeNull()->and($fresh->id)->not->toBe($this->d->id);
});

test('the husk is deleted 400 days after the purge, and not before', function () {
    purgeAccountNow($this->d->id);

    $this->travel(399)->days();
    app(StartDuePurges::class)->run();
    expect(DB::table('users')->where('id', $this->d->id)->exists())->toBeTrue();

    $this->travel(2)->days();
    app(StartDuePurges::class)->run();
    expect(DB::table('users')->where('id', $this->d->id)->exists())->toBeFalse();
});

test('subscription rows of a purged account go 400 days after the purge', function () {
    purgeAccountNow($this->d->id);

    $this->travel(401)->days();
    $this->artisan('billing:purge-claims')->assertSuccessful();

    expect(DB::table('subscriptions')->where('user_id', $this->d->id)->exists())->toBeFalse();
});

test('other people\'s data is never touched', function () {
    $patBefore = [DB::table('users')->where('id', $this->world['p']->id)->value('email_ciphertext'), DB::table('profiles')->where('user_id', $this->world['p']->id)->exists(), DB::table('categories')->where('user_id', $this->world['p']->id)->count()];

    purgeAccountNow($this->d->id);

    expect([DB::table('users')->where('id', $this->world['p']->id)->value('email_ciphertext'), DB::table('profiles')->where('user_id', $this->world['p']->id)->exists(), DB::table('categories')->where('user_id', $this->world['p']->id)->count()])->toBe($patBefore);
});
