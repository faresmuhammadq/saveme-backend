<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Dto\AccountView;
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

    public function address(string $userId): string
    {
        return $this->cipher->open($userId, $this->users->sealedEmail($userId));
    }
}
