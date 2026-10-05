<?php

namespace App\Modules\Identity\Application\Operators;

use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Account\UserRecord;
use App\Modules\Identity\Domain\Address\EmailAddress;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * How the back office finds a person (spec 021, FR-020, R-9): by exact
 * address, normalised as sign-in does and matched by its keyed lookup value
 * only, or by exact id. Never a partial or list search, and never the stored
 * address: only facts come back.
 */
final class AccountDirectory
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly AddressLookup $lookup,
    ) {}

    /**
     * Null when absent, purged or not an address.
     */
    public function byAddress(string $address): ?AccountFacts
    {
        $normalised = $this->normalise($address);

        if ($normalised === null) {
            return null;
        }

        $user = $this->users->findByLookup($this->lookup->for($normalised));

        return $user === null || $user->state === AccountState::Purged ? null : $this->facts($user);
    }

    /**
     * Null when $id is not a UUID or is unknown. A purged account returns its
     * id and state only.
     */
    public function byId(string $id): ?AccountFacts
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $user = $this->users->find(strtolower($id));

        return $user === null ? null : $this->facts($user);
    }

    /**
     * The normalised form of an address, or null when it is not one.
     */
    public function normalise(string $address): ?string
    {
        try {
            return (new EmailAddress($address))->normalised;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function facts(UserRecord $user): AccountFacts
    {
        if ($user->state === AccountState::Purged) {
            return new AccountFacts($user->id, $user->state->value, false, null, null, 0);
        }

        return new AccountFacts(
            id: $user->id,
            state: $user->state->value,
            verified: $user->isVerified(),
            createdAt: $this->users->createdAt($user->id),
            deletionScheduledFor: $user->deletionScheduledFor,
            activeDevices: $this->sessions->activeCountFor($user->id, now()->toDateTimeImmutable()),
        );
    }
}
