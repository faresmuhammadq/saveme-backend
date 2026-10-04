<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Domain\Ports\ProfileRepository;

/**
 * What Reporting needs of a person's profile (spec 019, research R-9): the
 * currency a cross-wallet report uses when none is asked for.
 */
final class ReportingPreferences
{
    public function __construct(private readonly ProfileRepository $profiles) {}

    /**
     * The profile's default currency; EGP, the column default, without a profile.
     */
    public function defaultCurrencyOf(string $userId): string
    {
        return $this->profiles->defaultCurrencyOf($userId);
    }
}
