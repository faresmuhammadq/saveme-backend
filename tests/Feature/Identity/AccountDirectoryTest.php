<?php

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Operators\AccountDirectory;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * Finding a person for an operator: exact address or exact id, facts only
 * (spec 021, FR-020, FR-021, FR-025; R-9).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->directory = app(AccountDirectory::class);
});

test('an address is normalised like sign-in and found by its lookup value', function () {
    $user = User::factory()->withEmail('Layla.Hassan@Example.test')->verified()->create();

    $facts = $this->directory->byAddress('  LAYLA.hassan@example.TEST ');

    expect($facts)->not->toBeNull()
        ->and($facts->id)->toBe($user->id)
        ->and($facts->state)->toBe('active')
        ->and($facts->verified)->toBeTrue()
        ->and($this->directory->normalise('  LAYLA.hassan@example.TEST '))->toBe('layla.hassan@example.test');
});

test('an unknown, purged or non-address term finds nothing', function () {
    $user = User::factory()->withEmail('gone@example.test')->create();
    User::query()->whereKey($user->id)->update(['state' => 'purged']);

    expect($this->directory->byAddress('nobody@example.test'))->toBeNull()
        ->and($this->directory->byAddress('gone@example.test'))->toBeNull()
        ->and($this->directory->byAddress('not an address'))->toBeNull()
        ->and($this->directory->byAddress(''))->toBeNull()
        ->and($this->directory->normalise('not an address'))->toBeNull();
});

test('an id that is not a uuid or is unknown finds nothing', function () {
    expect($this->directory->byId('12345'))->toBeNull()
        ->and($this->directory->byId('layla@example.test'))->toBeNull()
        ->and($this->directory->byId((string) Str::uuid7()))->toBeNull();
});

test('a purged account is found by id with its state only', function () {
    $user = User::factory()->create();
    User::query()->whereKey($user->id)->update(['state' => 'purged']);

    $facts = $this->directory->byId($user->id);

    expect($facts->id)->toBe($user->id)
        ->and($facts->state)->toBe('purged')
        ->and($facts->createdAt)->toBeNull()
        ->and($facts->activeDevices)->toBe(0);
});

test('the facts carry state, verification, creation, the deletion date and only live devices', function () {
    $user = User::factory()->pendingDeletion()->create();
    DeviceSession::factory()->count(2)->create(['user_id' => $user->id]);
    DeviceSession::factory()->revoked()->create(['user_id' => $user->id]);
    DeviceSession::factory()->create(['user_id' => $user->id, 'expires_at' => now()->subMinute()]);
    DeviceSession::factory()->create();

    $facts = $this->directory->byId($user->id);

    expect($facts->state)->toBe('pending_deletion')
        ->and($facts->verified)->toBeFalse()
        ->and($facts->createdAt->format('Y-m-d'))->toBe(now()->format('Y-m-d'))
        ->and($facts->deletionScheduledFor)->not->toBeNull()
        ->and($facts->activeDevices)->toBe(2);
});

test('only an existing active account counts as active', function () {
    $active = User::factory()->create();
    $pending = User::factory()->pendingDeletion()->create();
    $purged = User::factory()->create();
    User::query()->whereKey($purged->id)->update(['state' => 'purged']);
    $accounts = app(Accounts::class);

    expect($accounts->isActive($active->id))->toBeTrue()
        ->and($accounts->isActive($pending->id))->toBeFalse()
        ->and($accounts->isActive($purged->id))->toBeFalse()
        ->and($accounts->isActive((string) Str::uuid7()))->toBeFalse();
});
