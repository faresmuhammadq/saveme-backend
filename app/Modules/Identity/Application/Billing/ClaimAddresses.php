<?php

namespace App\Modules\Identity\Application\Billing;

use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Address\EmailAddress;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use InvalidArgumentException;

/**
 * What Billing may do with an address, and nothing more (spec 020, R-6;
 * security baseline, Finding a person by address). A claim's address is
 * normalised exactly as at registration and turned into its keyed lookup
 * value; neither step reads a user row, so submitting a claim cannot reveal
 * whether the address has an account. Billing never sees a cipher, a key or
 * a user row.
 */
final class ClaimAddresses
{
    public function __construct(
        private readonly AddressLookup $lookup,
        private readonly AttributeCipher $cipher,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
    ) {}

    /**
     * The normalised address and its lookup value. Pure: no account is read.
     *
     * @return array{address: string, lookup: string}
     *
     * @throws InvalidArgumentException when it is not an address
     */
    public function normaliseAndLookup(string $address): array
    {
        $normalised = (new EmailAddress($address))->normalised;

        return ['address' => $normalised, 'lookup' => $this->lookup->for($normalised)];
    }

    /**
     * The account holding the address, or null for none or a purged one.
     */
    public function accountFor(string $lookup): ?string
    {
        $user = $this->users->findByLookup($lookup);

        return $user === null || $user->state === AccountState::Purged ? null : $user->id;
    }

    /**
     * The lookup value of the person's own address, to compare with a claim's.
     */
    public function lookupOfUser(string $userId): string
    {
        return $this->users->lookupOf($userId);
    }

    public function isVerified(string $userId): bool
    {
        return $this->users->find($userId)?->isVerified() ?? false;
    }

    /**
     * The person's own address, opened with their key, and their profile
     * language; null for a purged or unknown account.
     *
     * @return array{address: string, locale: string}|null
     */
    public function addressAndLocaleOf(string $userId): ?array
    {
        $user = $this->users->find($userId);

        if ($user === null || $user->state === AccountState::Purged) {
            return null;
        }

        return [
            'address' => $this->cipher->open($userId, $this->users->sealedEmail($userId)),
            'locale' => $this->profiles->localeOf($userId),
        ];
    }
}
