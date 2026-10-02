<?php

namespace App\Modules\Wallets\Application\Activity;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Ports\ActivityLog;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use DateTimeZone;

/**
 * Reads a wallet's activity for its members (row S-07), newest first. Names
 * are opened only for actors who are active members of the wallet.
 */
final class ActivityQueryService
{
    public function __construct(
        private readonly ActivityLog $log,
        private readonly MembershipRepository $members,
        private readonly WalletAuthorizer $authorizer,
        private readonly DisplayNames $displayNames,
    ) {}

    /**
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<ActivityView>, next: array{value: string, id: string}|null}
     *
     * @throws WalletNotFound
     */
    public function page(string $userId, string $walletId, ?array $after, int $limit): array
    {
        $this->authorizer->authorize($userId, $walletId, Permission::S07ReadActivity);

        $records = $this->log->page($walletId, $after, $limit);
        $hasMore = count($records) > $limit;
        $page = array_slice($records, 0, $limit);

        $actorIds = array_values(array_unique(array_filter(array_map(fn ($entry): ?string => $entry->actorUserId, $page))));
        $active = $this->members->activeAmong($walletId, $actorIds);
        $names = $active === [] ? [] : $this->displayNames->of($active);

        $items = array_map(
            fn ($entry): ActivityView => new ActivityView($entry, $entry->actorUserId === null ? null : ($names[$entry->actorUserId] ?? null)),
            $page,
        );

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $last->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'), 'id' => $last->id];
        }

        return ['items' => $items, 'next' => $next];
    }
}
