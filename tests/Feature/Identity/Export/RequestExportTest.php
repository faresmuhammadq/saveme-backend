<?php

use App\Modules\Identity\Application\Jobs\GenerateExportJob;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Asking for an export (spec 022, FR-008 to FR-010, FR-017 to FR-019,
 * FR-021): one active export per person, 5 accepted per 24 hours, never
 * gated, served in the grace period.
 */

beforeEach(function () {
    Queue::fake();
    $this->user = person();
    $this->credential = signInAs()->json('data.credential');
});

test('an accepted request is 202 queued, audited once, and its job dispatched', function () {
    $response = exportCall('POST', $this->credential, '/exports', ['scope' => 'account']);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.scope', 'account')
        ->assertJsonPath('data.wallet_id', null)
        ->assertJsonPath('data.download_url', null);

    $audit = AuditEntry::query()->where('event', 'export_requested')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->detail)->toBe(['scope' => 'account'])
        ->and($audit[0]->actor_id)->toBe($this->user->id)
        ->and(DataExport::query()->count())->toBe(1);
    Queue::assertPushed(GenerateExportJob::class, fn (GenerateExportJob $job): bool => $job->exportId === $response->json('data.id'));
});

test('a second request while one is queued is 409 with that export as current, and is neither audited nor counted', function () {
    $first = exportCall('POST', $this->credential, '/exports', ['scope' => 'account']);
    $before = RateLimiter::attempts('identity-export:'.$this->user->id);

    $second = exportCall('POST', $this->credential, '/exports', ['scope' => 'account']);

    $second->assertStatus(409)
        ->assertJsonPath('code', 'EXPORT_IN_PROGRESS')
        ->assertJsonPath('current.id', $first->json('data.id'))
        ->assertJsonPath('current.status', 'queued');
    expect(DataExport::query()->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'export_requested')->count())->toBe(1)
        ->and(RateLimiter::attempts('identity-export:'.$this->user->id))->toBe($before);
    Queue::assertPushed(GenerateExportJob::class, 1);
});

test('a running export blocks too, and a failed or finished one does not', function () {
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account']);
    DataExport::query()->update(['status' => 'running']);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(409);

    DataExport::query()->update(['status' => 'failed', 'failure_code' => 'generation_failed']);
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(202);

    DataExport::query()->where('status', 'queued')->update(['status' => 'ready']);
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(202);
});

test('the sixth accepted request in 24 hours is 429 with Retry-After, and the budget comes back', function () {
    foreach (range(1, 5) as $attempt) {
        exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(202);
        DataExport::query()->update(['status' => 'failed', 'failure_code' => 'generation_failed']);
    }

    $sixth = exportCall('POST', $this->credential, '/exports', ['scope' => 'account']);

    $sixth->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    expect((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(DataExport::query()->count())->toBe(5);

    $this->travel(25)->hours();
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(202);
});

test('a request during the grace period is served and the scheduled date does not move', function () {
    DB::table('users')->where('id', $this->user->id)->update([
        'state' => 'pending_deletion',
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(30),
    ]);
    $scheduled = User::query()->find($this->user->id)->deletion_scheduled_for;

    exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->assertStatus(202);

    expect(User::query()->find($this->user->id)->deletion_scheduled_for->equalTo($scheduled))->toBeTrue()
        ->and(User::query()->find($this->user->id)->state)->toBe('pending_deletion');
});

test('an unverified account and a plan without the export feature are still served', function () {
    $unverified = person('new@example.test', verified: false);
    $credential = signInAs('new@example.test')->json('data.credential');

    exportCall('POST', $credential, '/exports', ['scope' => 'account'])->assertStatus(202);
    exportCall('GET', $credential, '/exports')->assertOk()->assertJsonCount(1, 'data');
    expect($unverified->refresh()->email_verified_at)->toBeNull();
});

test('a wallet export needs an active membership: a stranger and a deleted wallet are 404, nothing queued', function () {
    $owner = person('owner@example.test');
    $stranger = ownedWallet($owner->id);
    $deleted = ownedWallet($this->user->id, ['deleted_at' => now()]);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => $stranger->id])->assertStatus(404);
    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => $deleted->id])->assertStatus(404);

    expect(DataExport::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'export_requested')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a viewer may export a wallet', function () {
    $owner = person('owner@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $this->user->id, 'viewer');

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => $wallet->id])
        ->assertStatus(202)
        ->assertJsonPath('data.scope', 'wallet')
        ->assertJsonPath('data.wallet_id', $wallet->id);
    expect(AuditEntry::query()->where('event', 'export_requested')->first()->detail)->toBe(['scope' => 'wallet']);
});

test('a scope and wallet_id that disagree are a validation failure at /wallet_id', function () {
    $wallet = ownedWallet($this->user->id);

    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/wallet_id')->assertJsonPath('errors.0.code', 'REQUIRED');
    exportCall('POST', $this->credential, '/exports', ['scope' => 'account', 'wallet_id' => $wallet->id])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/wallet_id')->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
    exportCall('POST', $this->credential, '/exports', ['scope' => 'nope'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/scope')->assertJsonPath('errors.0.code', 'INVALID_ENUM_VALUE');
    exportCall('POST', $this->credential, '/exports', ['scope' => 'wallet', 'wallet_id' => 'not-a-uuid'])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/wallet_id')->assertJsonPath('errors.0.code', 'INVALID_ID');
});

test('the list is newest first and holds only the caller\'s exports', function () {
    $other = person('other@example.test');
    $this->travel(-3)->hours();
    DataExport::factory()->failed()->create(['user_id' => $this->user->id, 'requested_at' => now()]);
    $this->travelBack();
    $newer = exportCall('POST', $this->credential, '/exports', ['scope' => 'account'])->json('data.id');
    DataExport::factory()->create(['user_id' => $other->id]);

    $list = exportCall('GET', $this->credential, '/exports')->assertOk();

    expect($list->json('data.0.id'))->toBe($newer)->and($list->json('data'))->toHaveCount(2);

    exportCall('GET', $this->credential, '/exports/'.DataExport::query()->where('user_id', $other->id)->value('id'))->assertStatus(404);
});
