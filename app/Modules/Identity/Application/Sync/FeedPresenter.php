<?php

namespace App\Modules\Identity\Application\Sync;

use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Domain\Preferences\ProfileRecord;

/**
 * How an Identity feed row renders. Application cannot reach Http, so the
 * Http layer implements this with the resources the read endpoints use.
 */
interface FeedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function profile(ProfileRecord $profile, bool $currencyLocked): array;

    /**
     * @return array<string, mixed>
     */
    public function deviceSession(DeviceSessionView $view): array;
}
