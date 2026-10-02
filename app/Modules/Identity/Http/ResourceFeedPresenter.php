<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Application\Sync\FeedPresenter;
use App\Modules\Identity\Domain\Preferences\ProfileRecord;
use App\Modules\Identity\Http\Resources\DeviceSessionResource;
use App\Modules\Identity\Http\Resources\ProfileResource;

/**
 * Feed rows rendered by the same resource classes as the read endpoints.
 */
final class ResourceFeedPresenter implements FeedPresenter
{
    public function profile(ProfileRecord $profile, bool $currencyLocked): array
    {
        return ProfileResource::toArray($profile, $currencyLocked);
    }

    public function deviceSession(DeviceSessionView $view): array
    {
        return DeviceSessionResource::toArray($view);
    }
}
