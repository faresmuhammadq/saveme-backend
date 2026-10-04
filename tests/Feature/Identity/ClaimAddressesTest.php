<?php

use App\Modules\Identity\Application\Billing\ClaimAddresses;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;

/*
 * What Billing may do with an address (spec 020, R-6; security baseline,
 * Finding a person by address).
 */

test('the lookup value equals the account\'s for differently cased and padded input', function () {
    $user = person('ada@example.test');
    $addresses = app(ClaimAddresses::class);

    $result = $addresses->normaliseAndLookup('  Ada@Example.TEST ');

    expect($result['address'])->toBe('ada@example.test')
        ->and($result['lookup'])->toBe(app(AddressLookup::class)->for('ada@example.test'))
        ->and($result['lookup'])->toBe($addresses->lookupOfUser($user->id))
        ->and($addresses->accountFor($result['lookup']))->toBe($user->id);
});

test('something that is not an address is refused', function () {
    expect(fn () => app(ClaimAddresses::class)->normaliseAndLookup('not an address'))->toThrow(InvalidArgumentException::class);
});

test('normalising and looking up reads no account', function () {
    $users = Mockery::mock(UserRepository::class);
    $users->shouldNotReceive('findByLookup', 'find', 'lookupOf', 'sealedEmail');
    app()->instance(UserRepository::class, $users);

    expect(app(ClaimAddresses::class)->normaliseAndLookup('ada@example.test')['address'])->toBe('ada@example.test');
});

test('an unknown or purged address has no account', function () {
    $user = person('ada@example.test');
    $addresses = app(ClaimAddresses::class);
    $lookup = $addresses->normaliseAndLookup('ada@example.test')['lookup'];

    expect($addresses->accountFor($addresses->normaliseAndLookup('nobody@example.test')['lookup']))->toBeNull();

    User::query()->whereKey($user->id)->update(['state' => 'purged']);
    expect($addresses->accountFor($lookup))->toBeNull();
});

test('verification is reported as it stands', function () {
    $verified = person('ada@example.test');
    $unverified = person('bob@example.test', verified: false);
    $addresses = app(ClaimAddresses::class);

    expect($addresses->isVerified($verified->id))->toBeTrue()
        ->and($addresses->isVerified($unverified->id))->toBeFalse()
        ->and($addresses->isVerified((string) Str::uuid7()))->toBeFalse();
});

test('a person\'s own address and language are opened for a reminder, and a purged person has none', function () {
    $user = person('ada@example.test');
    Profile::query()->where('user_id', $user->id)->update(['locale' => 'ar']);
    $addresses = app(ClaimAddresses::class);

    expect($addresses->addressAndLocaleOf($user->id))->toBe(['address' => 'ada@example.test', 'locale' => 'ar']);

    User::query()->whereKey($user->id)->update(['state' => 'purged']);
    expect($addresses->addressAndLocaleOf($user->id))->toBeNull()
        ->and($addresses->addressAndLocaleOf((string) Str::uuid7()))->toBeNull();
});
