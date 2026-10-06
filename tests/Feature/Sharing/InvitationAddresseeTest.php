<?php

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\InvitationQueryService;
use App\Modules\Sharing\Infrastructure\Models\Invitation;

/*
 * Who an email invitation can be pushed to (spec 023, FR-025): an existing
 * account, not leaving for good, address verified, not the inviter.
 */

beforeEach(function () {
    $this->inviter = person('inviter@example.test');
    $this->wallet = ownedWallet($this->inviter->id);
});

function invitationTo(object $test, string $address, string ...$states): Invitation
{
    $factory = Invitation::factory();

    foreach ($states as $state) {
        $factory = $factory->{$state}();
    }

    // A link invitation and a closed one carry no address by constraint.
    $carriesAddress = array_diff($states, ['link', 'closed']) === $states;

    return $factory->create([
        'wallet_id' => $test->wallet->id,
        'invited_by' => $test->inviter->id,
        ...($carriesAddress ? ['address_lookup' => app(InvitationAddresses::class)->normaliseAndLookup($address)['lookup']] : []),
    ]);
}

function addressee(Invitation $invitation): ?string
{
    return app(InvitationQueryService::class)->verifiedAddresseeOf($invitation->id);
}

test('a pending email invitation to a registered, verified, active address names that account', function () {
    $invitee = person('invitee@example.test');

    expect(addressee(invitationTo($this, 'invitee@example.test')))->toBe($invitee->id);
});

test('the address is matched as normalised, whatever its case', function () {
    $invitee = person('invitee@example.test');

    expect(addressee(invitationTo($this, 'Invitee@Example.test')))->toBe($invitee->id);
});

test('a link invitation names nobody', function () {
    person('invitee@example.test');

    expect(addressee(invitationTo($this, 'invitee@example.test', 'link')))->toBeNull();
});

test('an address with no account names nobody', function () {
    expect(addressee(invitationTo($this, 'stranger@example.test')))->toBeNull();
});

test('an unverified address names nobody', function () {
    person('invitee@example.test', verified: false);

    expect(addressee(invitationTo($this, 'invitee@example.test')))->toBeNull();
});

test('a purging or purged account names nobody, a pending deletion still does', function () {
    $pending = User::factory()->withEmail('pending@example.test')->verified()->pendingDeletion()->create();
    $purging = User::factory()->withEmail('purging@example.test')->verified()->pendingDeletion()->create();
    User::query()->whereKey($purging->id)->update(['state' => 'purging']);

    expect(addressee(invitationTo($this, 'pending@example.test')))->toBe($pending->id)
        ->and(addressee(invitationTo($this, 'purging@example.test')))->toBeNull();

    $purged = User::factory()->withEmail('purged@example.test')->verified()->pendingDeletion()->create();
    $invitation = invitationTo($this, 'purged@example.test');
    User::query()->whereKey($purged->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null, 'deletion_requested_at' => null, 'deletion_scheduled_for' => null]);

    expect(addressee($invitation))->toBeNull();
});

test('the inviter own address names nobody', function () {
    expect(addressee(invitationTo($this, 'inviter@example.test')))->toBeNull();
});

test('a closed or expired invitation names nobody', function () {
    person('invitee@example.test');

    expect(addressee(invitationTo($this, 'invitee@example.test', 'closed')))->toBeNull()
        ->and(addressee(Invitation::factory()->expired()->create([
            'wallet_id' => $this->wallet->id,
            'invited_by' => $this->inviter->id,
            'address_lookup' => app(InvitationAddresses::class)->normaliseAndLookup('invitee@example.test')['lookup'],
        ])))->toBeNull();
});

test('an unknown invitation names nobody', function () {
    expect(app(InvitationQueryService::class)->verifiedAddresseeOf((string) Str::uuid7()))->toBeNull();
});
