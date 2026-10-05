<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Dto\AccountView;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\UserRepository;
use RuntimeException;

/**
 * Reads a person's own account, opening their sealed address.
 */
final class Accounts
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AttributeCipher $cipher,
    ) {}

    public function view(string $userId): AccountView
    {
        $user = $this->users->find($userId) ?? throw new RuntimeException('No such account.');

        return new AccountView(
            id: $user->id,
            email: $this->address($userId),
            emailVerified: $user->isVerified(),
            state: $user->state->value,
            deletionScheduledFor: $user->deletionScheduledFor,
        );
    }

    /**
     * Whether the account exists and is in good standing (not waiting to be
     * deleted, not purged): the test for granting or extending access.
     */
    public function isActive(string $userId): bool
    {
        return $this->users->find($userId)?->state === AccountState::Active;
    }

    /**
     * Which of the given people are gone for good: purged, or no longer
     * known at all (spec 022). A person still leaving is not.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function departedAmong(array $userIds): array
    {
        $states = $this->users->statesOf($userIds);

        return array_values(array_filter(
            $userIds,
            fn (string $id): bool => ! isset($states[$id]) || $states[$id] === AccountState::Purged,
        ));
    }

    public function address(string $userId): string
    {
        return $this->cipher->open($userId, $this->users->sealedEmail($userId));
    }
}
