<?php

namespace App\Modules\Sharing\Application\Sync;

use App\Modules\Sharing\Application\InvitationViews;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;

/**
 * Sharing's side of GET /sync/wallets/{id}/changes (spec 016, R-10): the
 * wallet's invitations above a position, pending live and closed as
 * tombstones, rendered as GET /wallets/{id}/invitations/{iid} renders them.
 * Positions come from the wallet's counter. The reader needs row S-01; the
 * Sync feed has already authorised them through Wallets' scope check.
 */
final class WalletFeedQuery
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationViews $views,
        private readonly WalletAuthorizer $authorizer,
        private readonly FeedPresenter $presenter,
    ) {}

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     *
     * @throws WalletNotFound
     */
    public function page(string $readerId, string $walletId, int $after, int $limit): array
    {
        $this->authorizer->authorize($readerId, $walletId, Permission::S01SeeInvitations);

        return array_map(
            fn (InvitationRecord $invitation): FeedRecord => new FeedRecord('invitation', $invitation->changeSeq, $this->presenter->invitation($this->views->of($invitation))),
            $this->invitations->changedIn($walletId, $after, $limit + 1),
        );
    }
}
