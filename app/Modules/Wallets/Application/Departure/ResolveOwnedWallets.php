<?php

namespace App\Modules\Wallets\Application\Departure;

use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\OwnershipTargetInvalid;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Domain\Refusals\WalletVersionConflict;

/**
 * Wallets' ownership step of an account purge (spec 022, FR-030 to FR-032,
 * FR-045, FR-054). Each live wallet the departing person owns is its own
 * unit of work, in ascending id: with another active member it passes to
 * the successor the shared rule names (the existing ownership transfer, so
 * its activity entry, `role_changed` audit entry, feed stamps and plan
 * demotion all apply); with nobody else it is deleted through the existing
 * wallet deletion. A wallet the person no longer owns is not listed, which
 * is what makes a second call a no-op.
 */
final class ResolveOwnedWallets implements AccountPurgeParticipant
{
    private const ATTEMPTS = 4;

    public function __construct(
        private readonly MembershipQueryService $memberships,
        private readonly WalletRepository $wallets,
        private readonly WalletService $service,
    ) {}

    public function step(): PurgeStep
    {
        return PurgeStep::ResolveOwnedWallets;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        $transferred = 0;
        $deleted = 0;

        foreach ($this->memberships->ownedLiveWalletIds($userId) as $walletId) {
            $outcome = $this->resolve($userId, $walletId, $context);

            $transferred += $outcome === 'transferred' ? 1 : 0;
            $deleted += $outcome === 'deleted' ? 1 : 0;
        }

        return PurgeCounts::of('wallets_transferred', $transferred)->plus(PurgeCounts::of('wallets_deleted', $deleted));
    }

    /**
     * @return 'transferred'|'deleted'|null
     */
    private function resolve(string $userId, string $walletId, PurgeContext $context): ?string
    {
        $present = fn (): array => [];

        for ($attempt = 1; ; $attempt++) {
            $wallet = $this->wallets->find($walletId);

            if ($wallet === null || $wallet->isTombstone() || $this->memberships->ownerOf($walletId) !== $userId) {
                return null;
            }

            $successor = $context->successorFor($walletId);

            try {
                if ($successor !== null) {
                    $this->service->transferOwnership($userId, $walletId, $successor->userId, $wallet->version, $present);

                    return 'transferred';
                }

                $this->service->delete($userId, $walletId, $wallet->version, $present);

                return 'deleted';
            } catch (WalletVersionConflict|OwnershipTargetInvalid $changed) {
                // The wallet or its members moved between the read and the lock: judge again.
                if ($attempt >= self::ATTEMPTS) {
                    throw $changed;
                }
            } catch (WalletNotFound) {
                return null;
            }
        }
    }
}
