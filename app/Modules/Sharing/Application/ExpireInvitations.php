<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Wallets\Application\ScopeWrite;
use App\Modules\Wallets\Application\WalletScopeWrites;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The hourly sweep (spec 016, R-14): closes pending invitations past their
 * expiry as `expired`, each with its own stamp and its address erased, in
 * batches of 200 per wallet lock. Acceptance checks the instant itself, so
 * the sweep's timing is no security matter; it makes the stored status the
 * truth on the feed. Idempotent and restartable.
 */
final class ExpireInvitations
{
    public const BATCH = 200;

    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly WalletScopeWrites $scopes,
    ) {}

    /**
     * @return int invitations closed
     */
    public function run(?DateTimeImmutable $now = null): int
    {
        $now ??= now()->toDateTimeImmutable();
        $closed = 0;
        $skipped = [];

        while (true) {
            $batch = array_values(array_filter(
                $this->invitations->expiredBefore($now, self::BATCH + count($skipped)),
                fn ($invitation): bool => ! isset($skipped[$invitation->id]),
            ));

            if ($batch === []) {
                break;
            }

            $byWallet = [];
            foreach (array_slice($batch, 0, self::BATCH) as $invitation) {
                $byWallet[$invitation->walletId][] = $invitation->id;
            }

            ksort($byWallet, SORT_STRING);

            foreach ($byWallet as $walletId => $ids) {
                try {
                    $closed += $this->scopes->within((string) $walletId, function (ScopeWrite $scope) use ($ids, $now): int {
                        $count = 0;

                        foreach ($ids as $id) {
                            $invitation = $this->invitations->findForUpdate($id);

                            if ($invitation === null || $invitation->status !== InvitationStatus::Pending || $invitation->expiresAt > $now) {
                                continue;
                            }

                            $this->invitations->close($id, InvitationStatus::Expired, $now, $scope->nextChangeSeq(), null);
                            $count++;
                        }

                        return $count;
                    });
                } catch (WalletNotFound) {
                    // The wallet is gone: nothing can be stamped for it, so leave its rows to the purge.
                    foreach ($ids as $id) {
                        $skipped[$id] = true;
                    }
                }
            }
        }

        Log::info('sharing_invitations_expired', ['closed' => $closed]);

        return $closed;
    }
}
