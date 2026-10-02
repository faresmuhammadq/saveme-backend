<?php

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * What Sharing may do with an invited address (spec 016, R-4, R-5; security
 * baseline, Finding a person by address).
 */

test('the lookup value is the registration lookup of the normalised address', function () {
    $addresses = app(InvitationAddresses::class);

    $result = $addresses->normaliseAndLookup('  Ada@Example.TEST ');

    expect($result['address'])->toBe('ada@example.test')
        ->and($result['lookup'])->toBe(app(AddressLookup::class)->for('ada@example.test'))
        ->and($addresses->normaliseAndLookup('ADA@example.test')['lookup'])->toBe($result['lookup']);
});

test('something that is not an address is refused', function () {
    expect(fn () => app(InvitationAddresses::class)->normaliseAndLookup('not an address'))->toThrow(InvalidArgumentException::class);
});

test('an address sealed under the inviter opens only with the same key and is not readable at rest', function () {
    $inviter = person('inviter@example.test');
    $other = person('other@example.test');
    $addresses = app(InvitationAddresses::class);

    $sealed = $addresses->seal($inviter->id, 'karim@example.test');

    expect($sealed)->not->toContain('karim')
        ->and($addresses->open($inviter->id, $sealed))->toBe('karim@example.test')
        ->and(fn () => $addresses->open($other->id, $sealed))->toThrow('Sealed value failed authentication.');
});

test('an account is found by lookup value, and a purged one is not', function () {
    $user = person('ada@example.test');
    $addresses = app(InvitationAddresses::class);
    $lookup = $addresses->normaliseAndLookup('ada@example.test')['lookup'];

    expect($addresses->userIdForLookup($lookup))->toBe($user->id)
        ->and($addresses->userIdForLookup($addresses->normaliseAndLookup('nobody@example.test')['lookup']))->toBeNull();

    User::query()->whereKey($user->id)->update(['state' => 'purged']);
    expect($addresses->userIdForLookup($lookup))->toBeNull();
});

test('a person\'s own lookup value and language are available to compare and to mail', function () {
    $user = person('ada@example.test');
    Profile::query()->where('user_id', $user->id)->update(['locale' => 'ar']);
    $addresses = app(InvitationAddresses::class);

    expect($addresses->lookupOfUser($user->id))->toBe($addresses->normaliseAndLookup('ada@example.test')['lookup'])
        ->and($addresses->localeOf($user->id))->toBe('ar');
});
