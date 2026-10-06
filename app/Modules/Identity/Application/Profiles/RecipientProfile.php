<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Application\Dto\RecipientView;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;

/**
 * Reads the language, timezone and account standing of a person Notifications
 * is about to address (spec 023, FR-011, FR-017, FR-021). A pending deletion
 * is still active (the reminder is the point); purging, purged and unknown
 * accounts are not.
 */
final class RecipientProfile
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
    ) {}

    public function of(string $userId): RecipientView
    {
        $state = $this->users->statesOf([$userId])[$userId] ?? null;

        return new RecipientView(
            $this->profiles->localeOf($userId),
            $this->profiles->timezoneOf($userId),
            $state === AccountState::Active || $state === AccountState::PendingDeletion,
        );
    }
}
