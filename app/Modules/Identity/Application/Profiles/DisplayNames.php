<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;

/**
 * Display names for people who share a wallet (spec 010, R-8). The security
 * baseline lets other members of a shared wallet read a display name, and
 * nothing else of the profile. Each name is opened with its owner's own key,
 * for the response only.
 */
final class DisplayNames
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AttributeCipher $cipher,
    ) {}

    /**
     * @param  list<string>  $userIds
     * @return array<string, ?string> every given id, null where the person set no name
     */
    public function of(array $userIds): array
    {
        $sealed = $this->profiles->sealedDisplayNames($userIds);
        $names = [];

        foreach ($userIds as $userId) {
            $names[$userId] = isset($sealed[$userId]) ? $this->cipher->open($userId, $sealed[$userId]) : null;
        }

        return $names;
    }
}
