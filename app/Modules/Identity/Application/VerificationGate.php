<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\EmailNotVerified;

/**
 * The verification gate as an application check, for writes that do not
 * arrive over HTTP (spec 007, R-17).
 */
final class VerificationGate
{
    public function __construct(private readonly UserRepository $users) {}

    public function assertVerified(string $userId): void
    {
        $user = $this->users->find($userId);

        if ($user === null || ! $user->isVerified()) {
            throw new EmailNotVerified;
        }
    }
}
