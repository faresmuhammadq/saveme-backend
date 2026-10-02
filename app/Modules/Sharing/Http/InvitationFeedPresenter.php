<?php

namespace App\Modules\Sharing\Http;

use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Application\Sync\FeedPresenter;
use App\Modules\Sharing\Http\Resources\InvitationResource;

/**
 * Feed rows rendered by the same resource as the read endpoints.
 */
final class InvitationFeedPresenter implements FeedPresenter
{
    public function invitation(InvitationView $view): array
    {
        return InvitationResource::toArray($view);
    }
}
