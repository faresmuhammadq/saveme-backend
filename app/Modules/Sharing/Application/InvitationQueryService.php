<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Sharing\Application\Dto\InvitationPage;
use App\Modules\Sharing\Application\Dto\InvitationPreview;
use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Sharing\Domain\Refusals\InvitationNotFound;
use App\Modules\Sharing\Domain\Refusals\TokenNotLive;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use DateTimeZone;

/**
 * Reads invitations for the wallet's members (row S-01) and builds the
 * preview a token offers. Never an amount, a balance or an address outside a
 * pending invitation's own read (spec 016, A-5).
 */
final class InvitationQueryService
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationViews $views,
        private readonly WalletAuthorizer $authorizer,
        private readonly WalletQueryService $wallets,
        private readonly MembershipQueryService $members,
        private readonly DisplayNames $displayNames,
    ) {}

    /**
     * The wallet's pending invitations, oldest first, one page.
     *
     * @param  array{value: string, id: string}|null  $after
     *
     * @throws WalletNotFound
     */
    public function list(string $userId, string $walletId, ?array $after, int $limit): InvitationPage
    {
        $this->authorizer->authorize($userId, $walletId, Permission::S01SeeInvitations);

        $records = $this->invitations->listLive($walletId, $after, $limit);
        $hasMore = count($records) > $limit;
        $page = array_slice($records, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $last->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id];
        }

        return new InvitationPage(array_map(fn (InvitationRecord $record): InvitationView => $this->views->of($record), $page), $next);
    }

    /**
     * One invitation of the wallet, a tombstone included.
     *
     * @throws WalletNotFound
     * @throws InvitationNotFound
     */
    public function find(string $userId, string $walletId, string $invitationId): InvitationView
    {
        $this->authorizer->authorize($userId, $walletId, Permission::S01SeeInvitations);

        $invitation = $this->invitations->find($invitationId);

        if ($invitation === null || $invitation->walletId !== $walletId) {
            throw new InvitationNotFound;
        }

        return $this->views->of($invitation);
    }

    /**
     * What a live invitation offers the caller.
     *
     * @throws TokenNotLive when the wallet is gone
     */
    public function previewFor(InvitationRecord $invitation, string $userId): InvitationPreview
    {
        $name = $this->wallets->liveName($invitation->walletId) ?? throw new TokenNotLive;

        return new InvitationPreview(
            invitationId: $invitation->id,
            walletId: $invitation->walletId,
            walletName: $name,
            role: $invitation->role,
            invitedByDisplayName: $this->displayNames->of([$invitation->invitedBy])[$invitation->invitedBy] ?? null,
            expiresAt: $invitation->expiresAt,
            alreadyMember: $this->members->roleOf($userId, $invitation->walletId) !== null,
        );
    }
}
