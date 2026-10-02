<?php

namespace App\Modules\Sharing\Application\Sync;

use App\Modules\Sharing\Application\Dto\InvitationView;

/**
 * How a Sharing feed row renders. Application cannot reach Http, so the Http
 * layer implements this with the resource the read endpoints use, which is
 * what makes a feed record identical to its read (spec 013, FR-005).
 */
interface FeedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function invitation(InvitationView $view): array;
}
