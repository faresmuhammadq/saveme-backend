<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\RoleChangedDetail;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Application\Dto\SavedWallet;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Domain\Events\WalletCreated;
use App\Modules\Wallets\Domain\Events\WalletDeleted;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Refusals\OwnershipTargetInvalid;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Domain\Refusals\WalletVersionConflict;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use App\Modules\Wallets\Domain\Wallets\WalletRules;
use App\Modules\Wallets\Domain\Wallets\WalletType;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every write to a wallet and its memberships (constitution, Wallets &
 * Ledger). Each write is one transaction under the wallet row lock: the
 * lock serialises the scope, every touched record takes its own next
 * change_seq from the wallet's counter and the wallet takes the last, the
 * cached balance is recomputed when the opening balance moves, and events
 * follow the commit (spec 010, R-2 to R-4, R-9, R-10).
 *
 * Precedence for every write: not-found, forbidden, not-entitled, a write
 * to a tombstone, an identical replay, a stale version, the state rules.
 */
final class WalletService
{
    /**
     * @param  iterable<WalletDeletionParticipant>  $participants  modules whose records go with a deleted wallet
     */
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly MembershipRepository $members,
        private readonly ScopeSequence $sequence,
        private readonly PostingTotals $postings,
        private readonly WalletAuthorizer $authorizer,
        private readonly EntitlementService $entitlements,
        private readonly AuditLog $audit,
        private readonly iterable $participants = [],
    ) {}

    /**
     * Creates the wallet (`$version` 0) or replaces its writable members.
     *
     * @param  array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}  $values
     * @param  Closure(WalletView): array<string, mixed>  $present  how a read renders the wallet, for a conflict's `current`
     */
    public function save(string $actorId, string $walletId, array $values, int $version, Closure $present): SavedWallet
    {
        $values['name'] = trim($values['name']);

        if ($this->wallets->find($walletId) === null) {
            if ($version > 0) {
                throw new WalletNotFound;
            }

            try {
                return $this->create($actorId, $walletId, $values);
            } catch (UniqueConstraintViolationException) {
                // A concurrent create of the same id won: this one is a replay or a foreign id.
            }
        }

        return new SavedWallet($this->write($actorId, $walletId, fn (): array => $values, $version, $present), false);
    }

    /**
     * Changes the members sent; the rest stay as stored. Never creates.
     *
     * @param  array<string, mixed>  $partial  any of name, currency, opening_balance_minor, icon, color
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    public function patch(string $actorId, string $walletId, array $partial, int $version, Closure $present): WalletView
    {
        if (isset($partial['name']) && is_string($partial['name'])) {
            $partial['name'] = trim($partial['name']);
        }

        return $this->write($actorId, $walletId, fn (WalletRecord $current): array => array_replace($current->writableValues(), $partial), $version, $present);
    }

    /**
     * Archives the wallet. One way; archiving an archived wallet changes nothing.
     *
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    public function archive(string $actorId, string $walletId, int $version, Closure $present): WalletView
    {
        return DB::transaction(function () use ($actorId, $walletId, $version, $present): WalletView {
            [$current, $role] = $this->lockAndAuthorize($actorId, $walletId, Permission::W03Archive);
            $this->refuseTombstone($current, $role, $present);

            if ($current->archivedAt !== null) {
                return $this->view($current, $role);
            }

            $this->refuseStale($current, $version, $role, $present);

            $this->bump($walletId, $version, ['archived_at' => self::instant(now()->toDateTimeImmutable())]);

            return $this->view($this->reload($walletId), $role);
        });
    }

    /**
     * Deletes the wallet: a tombstone for the wallet and every record in it,
     * in one unit of work. Deleting a tombstone returns it.
     *
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    public function delete(string $actorId, string $walletId, int $version, Closure $present): WalletView
    {
        $view = DB::transaction(function () use ($actorId, $walletId, $version, $present): WalletView {
            [$current, $role] = $this->lockAndAuthorize($actorId, $walletId, Permission::W04Delete);

            if ($current->isTombstone()) {
                return $this->view($current, $role);
            }

            $this->refuseStale($current, $version, $role, $present);

            $at = now()->toDateTimeImmutable();
            $nextChangeSeq = fn (): int => $this->sequence->nextFor($walletId);

            $this->members->tombstoneAllIn($walletId, $at, $nextChangeSeq);

            foreach ($this->participants as $participant) {
                $participant->tombstoneWithin($walletId, $at, $nextChangeSeq);
            }

            $this->bump($walletId, $version, ['deleted_at' => self::instant($at)]);

            event(new WalletDeleted(...Events::stamp(), walletId: $walletId, actorUserId: $actorId));

            return $this->view($this->reload($walletId), $role);
        });

        return $view;
    }

    /**
     * The owner hands ownership to another active member and becomes an
     * editor. Naming the person who already owns the wallet changes nothing.
     *
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    public function transferOwnership(string $actorId, string $walletId, string $targetUserId, int $version, Closure $present): WalletView
    {
        return DB::transaction(function () use ($actorId, $walletId, $targetUserId, $version, $present): WalletView {
            $current = $this->wallets->findForUpdate($walletId) ?? throw new WalletNotFound;
            $role = $this->authorizer->memberRole($actorId, $walletId);

            if (! $current->isTombstone() && $targetUserId !== $actorId && $this->members->ownerOf($walletId) === $targetUserId) {
                return $this->view($current, $role);
            }

            $this->authorizer->authorize($actorId, $walletId, Permission::W05TransferOwnership);
            $this->refuseTombstone($current, $role, $present);
            $this->refuseStale($current, $version, $role, $present);

            $target = $targetUserId === $actorId ? null : $this->members->activeFor($walletId, $targetUserId);
            $actor = $this->members->activeFor($walletId, $actorId);

            if ($target === null || $actor === null) {
                throw new OwnershipTargetInvalid;
            }

            $this->members->changeRole($actor->id, Role::Editor, $this->sequence->nextFor($walletId));
            $this->members->changeRole($target->id, Role::Owner, $this->sequence->nextFor($walletId));
            $this->bump($walletId, $version, []);

            $this->audit->record(AuditRecord::byUser(
                AuditEvent::RoleChanged,
                $actorId,
                SubjectType::Wallet,
                $walletId,
                new RoleChangedDetail($actorId, $targetUserId),
            ));

            event(new WalletOwnershipTransferred(...Events::stamp(), walletId: $walletId, previousOwnerId: $actorId, newOwnerId: $targetUserId));

            return $this->view($this->reload($walletId), Role::Editor);
        });
    }

    /**
     * @param  array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}  $values
     */
    private function create(string $actorId, string $walletId, array $values): SavedWallet
    {
        $max = $this->entitlements->forUser($actorId)->quota(Feature::WalletsMax);

        if ($max !== null && $this->wallets->countOwnedLive($actorId) >= $max) {
            throw new FeatureNotEntitled;
        }

        $view = DB::transaction(function () use ($actorId, $walletId, $values): WalletView {
            $this->wallets->insert($walletId, $values, $this->postings->sumFor($walletId));
            $this->members->insert($walletId, $actorId, Role::Owner, $this->sequence->nextFor($walletId));
            $this->sequence->nextFor($walletId);

            event(new WalletCreated(...Events::stamp(), walletId: $walletId, ownerUserId: $actorId, type: $values['type'], currency: $values['currency']));

            return $this->view($this->reload($walletId), Role::Owner);
        });

        return new SavedWallet($view, true);
    }

    /**
     * The shared update path of save and patch.
     *
     * @param  Closure(WalletRecord): array<string, mixed>  $valuesFrom  the full writable values wanted, given the stored wallet
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    private function write(string $actorId, string $walletId, Closure $valuesFrom, int $version, Closure $present): WalletView
    {
        return DB::transaction(function () use ($actorId, $walletId, $valuesFrom, $version, $present): WalletView {
            [$current, $role] = $this->lockAndAuthorize($actorId, $walletId, Permission::W02EditWallet);
            $this->refuseTombstone($current, $role, $present);

            /** @var array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string} $values */
            $values = $valuesFrom($current);

            if ($values === $current->writableValues()) {
                return $this->view($current, $role);
            }

            $this->refuseStale($current, $version, $role, $present);

            WalletRules::assertImmutablesKept($current, WalletType::from($values['type']), $values['currency'], $this->postings->hasAny($walletId));

            $this->bump($walletId, $version, [
                'name' => $values['name'],
                'currency' => $values['currency'],
                'opening_balance_minor' => $values['opening_balance_minor'],
                'icon' => $values['icon'],
                'color' => $values['color'],
            ]);

            if ($values['opening_balance_minor'] !== $current->openingBalanceMinor) {
                $this->wallets->refreshBalance($walletId, $this->postings->sumFor($walletId));
            }

            return $this->view($this->reload($walletId), $role);
        });
    }

    /**
     * Takes the wallet row lock, then decides the permission.
     *
     * @return array{WalletRecord, Role}
     */
    private function lockAndAuthorize(string $actorId, string $walletId, Permission $permission): array
    {
        $current = $this->wallets->findForUpdate($walletId) ?? throw new WalletNotFound;

        return [$current, $this->authorizer->authorize($actorId, $walletId, $permission)];
    }

    /**
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    private function refuseTombstone(WalletRecord $current, Role $role, Closure $present): void
    {
        if ($current->isTombstone()) {
            throw new WalletVersionConflict($present($this->view($current, $role)));
        }
    }

    /**
     * @param  Closure(WalletView): array<string, mixed>  $present
     */
    private function refuseStale(WalletRecord $current, int $version, Role $role, Closure $present): void
    {
        if ($version !== $current->version) {
            throw new WalletVersionConflict($present($this->view($current, $role)));
        }
    }

    /**
     * Moves the wallet's own version, applies its columns, and takes the
     * wallet's final change_seq of the write (the scope's highest).
     *
     * @param  array<string, scalar|null>  $columns
     */
    private function bump(string $walletId, int $version, array $columns): void
    {
        if (! $this->wallets->updateIfVersion($walletId, $version, $columns)) {
            throw new RuntimeException('The wallet changed under its own lock.');
        }

        $this->sequence->nextFor($walletId);
    }

    private function reload(string $walletId): WalletRecord
    {
        return $this->wallets->find($walletId) ?? throw new WalletNotFound;
    }

    private function view(WalletRecord $wallet, Role $role): WalletView
    {
        return new WalletView($wallet, $role, $this->postings->hasAny($wallet->id));
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
