<?php

namespace App\Modules\Sharing\Http;

use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Application\Sync\FeedPresenter;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Http\Resources\InvitationResource;
use App\Modules\Sharing\Http\Resources\SharedBudgetInvitationResource;

/**
 * Feed rows rendered by the same resource as the read endpoints.
 */
final class InvitationFeedPresenter implements FeedPresenter
{
    public function invitation(InvitationView $view): array
    {
        return InvitationResource::toArray($view);
    }

    public function sharedBudgetInvitation(SharedBudgetInvitationRecord $invitation): array
    {
        return SharedBudgetInvitationResource::toArray($invitation);
    }
}
