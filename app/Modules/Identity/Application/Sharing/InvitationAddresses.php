<?php

namespace App\Modules\Identity\Application\Sharing;

use App\Modules\Identity\Domain\Address\EmailAddress;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use InvalidArgumentException;

/**
 * What Sharing may do with an invited address, and nothing more (spec 016,
 * R-4, R-5; security baseline, Finding a person by address). An address is
 * normalised exactly as at registration, kept sealed under the inviter's data
 * key beside its keyed lookup value, and found again by lookup value only.
 * Sharing never sees a cipher, a key or a user row.
 */
final class InvitationAddresses
{
    public function __construct(
        private readonly AddressLookup $lookup,
        private readonly AttributeCipher $cipher,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
    ) {}

    /**
     * The normalised address and its lookup value.
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
     * Seals the normalised address under the inviter's data key.
     */
    public function seal(string $inviterId, string $normalisedAddress): string
    {
        return $this->cipher->seal($inviterId, $normalisedAddress);
    }

    /**
     * Opens an address sealed by `seal` with the same inviter's key.
     */
    public function open(string $inviterId, string $sealed): string
    {
        return $this->cipher->open($inviterId, $sealed);
    }

    /**
     * The account holding the address, or null for none or a purged one.
     */
    public function userIdForLookup(string $lookup): ?string
    {
        $user = $this->users->findByLookup($lookup);

        return $user === null || $user->state->isGone() ? null : $user->id;
    }

    /**
     * The account holding the address, only when it can be told about an
     * invitation by push: not leaving for good (a pending deletion still
     * counts) and its address verified (spec 023, FR-025).
     */
    public function verifiedUserIdForLookup(string $lookup): ?string
    {
        $user = $this->users->findByLookup($lookup);

        return $user === null || $user->state->isGone() || ! $user->isVerified() ? null : $user->id;
    }

    /**
     * The lookup value of the person's own address, to compare with an
     * invitation's.
     */
    public function lookupOfUser(string $userId): string
    {
        return $this->users->lookupOf($userId);
    }

    /**
     * The person's profile language.
     */
    public function localeOf(string $userId): string
    {
        return $this->profiles->localeOf($userId);
    }
}
